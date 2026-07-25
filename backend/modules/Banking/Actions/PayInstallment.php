<?php

declare(strict_types=1);

namespace Modules\Banking\Actions;

use App\Core\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Banking\Exceptions\BankingException;
use Modules\Banking\Models\Loan;
use Modules\Banking\Models\LoanInstallment;
use Modules\Ledger\Actions\RecordTransaction;
use Modules\Ledger\Models\Transaction;

/**
 * Records a payment against one instalment and posts it to the ledger.
 *
 * Part-payments are ordinary here, not an edge case: a borrower who pays half
 * an instalment has paid half an instalment, and the schedule has to be able to
 * say so.
 */
final readonly class PayInstallment
{
    public function __construct(private RecordTransaction $record) {}

    /**
     * @param  array{
     *   paid_at?: \DateTimeInterface|null,
     *   penalty?: int|null,
     *   idempotency_key?: string|null,
     *   notes?: string|null,
     * }  $options
     */
    public function handle(LoanInstallment $installment, int $amount, array $options = []): LoanInstallment
    {
        if ($amount <= 0) {
            throw BankingException::nonPositivePayment();
        }

        $loan = $installment->loan;

        if ($loan === null) {
            throw BankingException::loanNotFound((string) $installment->loan_id);
        }

        $penalty = max(0, (int) ($options['penalty'] ?? 0));

        // Normalised to the type the column is cast to, so the value written to
        // paid_at is the same shape whether the caller passed one or not.
        $paidAt = Carbon::instance($options['paid_at'] ?? now());

        return DB::transaction(function () use ($installment, $loan, $amount, $penalty, $paidAt, $options): LoanInstallment {
            if ($penalty > 0) {
                $installment->penalty_amount += $penalty;
            }

            $remaining = $installment->remaining();

            if ($remaining === 0) {
                throw BankingException::installmentAlreadyPaid((int) $installment->number);
            }

            if ($amount > $remaining) {
                throw BankingException::overpayment($amount, $remaining);
            }

            $transaction = $this->record->handle([
                'type' => Transaction::TYPE_EXPENSE,
                'account_id' => $loan->account_id,
                'amount' => $amount,
                'currency' => $loan->currency,
                'occurred_at' => $paidAt,
                'description' => 'Loan instalment '.$installment->number,
                'notes' => $options['notes'] ?? null,
                'reference' => $loan->id.'#'.$installment->number,
                'source' => 'manual',
                'idempotency_key' => $options['idempotency_key'] ?? null,
            ]);

            $installment->paid_amount += $amount;
            $settled = $installment->remaining() === 0;

            $installment->status = $settled
                ? LoanInstallment::STATUS_PAID
                : LoanInstallment::STATUS_PARTIAL;

            $installment->paid_at = $settled ? $paidAt : $installment->paid_at;

            // One column, many possible payments: it holds the most recent
            // posting, which is the one a user chasing "what did I just pay"
            // is looking for.
            $installment->transaction_id = $transaction->id;
            $installment->save();

            $this->refreshLoan($loan);

            return $installment->refresh();
        });
    }

    /**
     * Recomputes the loan's outstanding principal from its instalments.
     *
     * Derived rather than decremented: after a part-payment, a correction or a
     * regenerated schedule, a running subtraction drifts and there is no way to
     * tell that it has. Reading the rows back cannot drift.
     */
    private function refreshLoan(Loan $loan): void
    {
        $installments = LoanInstallment::query()
            ->where('loan_id', $loan->id)
            ->get();

        $paidPrincipal = Money::sum(
            $installments->map(fn (LoanInstallment $row) => $row->principalPaid($loan->currency)),
            $loan->currency,
        );

        $outstanding = $loan->principalMoney()->minus($paidPrincipal);

        $loan->forceFill([
            'outstanding_balance' => $outstanding->minorUnits,
            'status' => $outstanding->isZero() && $installments->every(fn (LoanInstallment $row) => $row->isSettled())
                ? Loan::STATUS_CLOSED
                : $loan->status,
        ])->save();
    }
}
