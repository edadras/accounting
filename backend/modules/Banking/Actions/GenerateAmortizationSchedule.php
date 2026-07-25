<?php

declare(strict_types=1);

namespace Modules\Banking\Actions;

use App\Core\Money\Currency;
use App\Core\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Banking\Exceptions\BankingException;
use Modules\Banking\Models\Loan;
use Modules\Banking\Models\LoanInstallment;

/**
 * Builds a loan's instalment table.
 *
 * The invariant the whole module rests on: the principal parts of the
 * instalments sum to exactly the loan principal, and the balance reaches
 * exactly zero on the last one. A schedule that is a minor unit out is a
 * schedule that leaves the borrower owing a rial forever, so rounding is never
 * left to chance here — every remainder is placed deliberately.
 */
final readonly class GenerateAmortizationSchedule
{
    /**
     * Computes and persists the schedule, replacing any existing one.
     *
     * @return Collection<int, LoanInstallment>
     */
    public function handle(Loan $loan): Collection
    {
        $rows = $this->rows($loan);

        return DB::transaction(function () use ($loan, $rows): Collection {
            // Regenerating is a full replacement: a half-old, half-new schedule
            // would not sum to the principal.
            LoanInstallment::query()->where('loan_id', $loan->id)->delete();

            $installments = collect($rows)->map(fn (array $row) => LoanInstallment::query()->create([
                'workspace_id' => $loan->workspace_id,
                'loan_id' => $loan->id,
                'number' => $row['number'],
                'due_date' => $row['due_date'],
                'principal_part' => $row['principal_part'],
                'interest_part' => $row['interest_part'],
                'total_amount' => $row['total_amount'],
                'paid_amount' => 0,
                'penalty_amount' => 0,
                'status' => LoanInstallment::STATUS_DUE,
            ]));

            $loan->forceFill([
                'outstanding_balance' => $loan->principal,
                'status' => Loan::STATUS_ACTIVE,
            ])->save();

            return $installments->values();
        });
    }

    /**
     * The schedule as plain rows, without touching the database.
     *
     * @return list<array{number:int,due_date:\DateTimeInterface,principal_part:int,interest_part:int,total_amount:int,closing_balance:int}>
     */
    public function rows(Loan $loan): array
    {
        $count = (int) $loan->installments_count;

        if ($count < 1) {
            throw BankingException::invalidInstallmentsCount($count);
        }

        if ($loan->principal <= 0) {
            throw BankingException::nonPositivePrincipal();
        }

        if (! in_array($loan->interest_type, Loan::INTEREST_TYPES, true)) {
            throw BankingException::unknownInterestType((string) $loan->interest_type);
        }

        $currency = Currency::of($loan->currency);

        $parts = $loan->interest_type === Loan::INTEREST_COMPOUND
            ? $this->annuityParts($loan, $currency, $count)
            : $this->simpleParts($loan, $currency, $count);

        // start_date is a date-cast column, so it arrives as a Carbon already.
        $start = CarbonImmutable::instance($loan->start_date);

        $rows = [];
        $balance = $loan->principal;

        foreach ($parts as $index => [$principalPart, $interestPart]) {
            $balance -= $principalPart->minorUnits;

            $rows[] = [
                'number' => $index + 1,
                'due_date' => $start->addMonthsNoOverflow($index + 1),
                'principal_part' => $principalPart->minorUnits,
                'interest_part' => $interestPart->minorUnits,
                'total_amount' => $principalPart->plus($interestPart)->minorUnits,
                'closing_balance' => $balance,
            ];
        }

        return $rows;
    }

    /**
     * Flat interest: the whole charge is worked out once and shared evenly.
     *
     * Both the principal and the interest go through allocateEvenly, which
     * hands the leftover minor units to the first instalments rather than
     * dropping them.
     *
     * @return list<array{0: Money, 1: Money}>
     */
    private function simpleParts(Loan $loan, Currency $currency, int $count): array
    {
        $principal = Money::of($loan->principal, $currency);

        $years = $count / Loan::PERIODS_PER_YEAR;
        $totalInterest = $principal->multipliedBy(((float) $loan->interest_rate) / 100 * $years);

        $principalParts = $principal->allocateEvenly($count);
        $interestParts = $totalInterest->allocateEvenly($count);

        $parts = [];

        for ($i = 0; $i < $count; $i++) {
            $parts[] = [$principalParts[$i], $interestParts[$i]];
        }

        return $parts;
    }

    /**
     * Annuity: a level payment, with interest charged on the balance that is
     * actually still outstanding each period.
     *
     * The final instalment repays whatever balance remains rather than a
     * recomputed figure. That single choice is what makes the principal parts
     * sum to the principal exactly no matter how the intermediate roundings
     * fell.
     *
     * @return list<array{0: Money, 1: Money}>
     */
    private function annuityParts(Loan $loan, Currency $currency, int $count): array
    {
        $rate = $loan->periodicRate();

        if ($rate <= 0.0) {
            // No interest to compound; an even split is the correct annuity.
            return $this->interestFreeParts($loan, $currency, $count);
        }

        $balance = Money::of($loan->principal, $currency);
        $payment = $this->levelPayment($balance, $rate, $count);

        $parts = [];

        for ($period = 1; $period <= $count; $period++) {
            $interest = $balance->multipliedBy($rate);

            if ($period === $count) {
                $principalPart = $balance;
            } else {
                $principalPart = $payment->minus($interest);

                // A payment that does not even cover the period's interest
                // would otherwise amortise negatively. Clamping keeps the
                // schedule monotonic; the final instalment absorbs the rest.
                if ($principalPart->isNegative()) {
                    $principalPart = Money::zero($currency);
                } elseif ($principalPart->greaterThan($balance)) {
                    $principalPart = $balance;
                }
            }

            $balance = $balance->minus($principalPart);
            $parts[] = [$principalPart, $interest];
        }

        return $parts;
    }

    /** @return list<array{0: Money, 1: Money}> */
    private function interestFreeParts(Loan $loan, Currency $currency, int $count): array
    {
        $zero = Money::zero($currency);

        return array_map(
            fn (Money $slice) => [$slice, $zero],
            Money::of($loan->principal, $currency)->allocateEvenly($count),
        );
    }

    /** A = P·i / (1 − (1+i)^−n), rounded to the currency's smallest unit. */
    private function levelPayment(Money $principal, float $rate, int $count): Money
    {
        $factor = $rate / (1 - (1 + $rate) ** (-$count));

        return $principal->multipliedBy($factor);
    }
}
