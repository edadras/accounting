<?php

declare(strict_types=1);

namespace Modules\AI\Actions;

use App\Core\Money\Currency;
use App\Core\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Modules\Banking\Models\Check;
use Modules\Banking\Models\LoanInstallment;
use Modules\Core\Support\WorkspaceContext;
use Modules\Ledger\Actions\ExchangeRateResolver;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;

/**
 * Where the balance lands if the last few weeks keep happening.
 *
 * Arithmetic only, and integer arithmetic at that — every component is
 * returned alongside the answer so the number can be checked by hand:
 *
 *     projected = starting + (daily_net × horizon) − obligations
 *
 * `daily_net` truncates rather than rounds. A forecast that drifts by a
 * fraction of a unit per day is a forecast nobody can reproduce, and being
 * reproducible matters more here than the last minor unit.
 */
final readonly class ForecastCashflow
{
    public function __construct(
        private WorkspaceContext $context,
        private ExchangeRateResolver $rates,
    ) {}

    /** @return array<string, mixed> */
    public function handle(int $horizonDays = 0, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->startOfDay();
        $horizonDays = $horizonDays > 0 ? $horizonDays : (int) config('ai.forecast.horizon_days', 30);
        $lookbackDays = max(1, (int) config('ai.forecast.lookback_days', 30));

        $base = Currency::of($this->context->baseCurrency());
        $windowStart = $now->subDays($lookbackDays);

        $income = $this->observed(Transaction::TYPE_INCOME, $windowStart, $now->endOfDay(), $base);
        $expense = $this->observed(Transaction::TYPE_EXPENSE, $windowStart, $now->endOfDay(), $base);

        $net = $income - $expense;
        $dailyNet = intdiv($net, $lookbackDays);
        $projectedNet = $dailyNet * $horizonDays;

        $starting = $this->startingBalance($base);
        $to = $now->addDays($horizonDays);
        $obligations = $this->obligations($now, $to, $base);

        return [
            'currency' => $base->code,
            'from' => $now->toDateString(),
            'to' => $to->toDateString(),
            'horizon_days' => $horizonDays,
            'lookback_days' => $lookbackDays,
            'observed_income' => $income,
            'observed_expense' => $expense,
            'observed_net' => $net,
            'daily_net' => $dailyNet,
            'projected_net' => $projectedNet,
            'starting_balance' => $starting,
            'obligations' => $obligations,
            'projected_balance' => $starting + $projectedNet - $obligations,
        ];
    }

    private function observed(string $type, CarbonImmutable $from, CarbonImmutable $to, Currency $base): int
    {
        return (int) Transaction::query()
            ->ofType($type)
            ->between($from, $to)
            // Rows stamped against a different base currency belong to a
            // different yardstick; summing them together would be nonsense.
            ->where('base_currency', $base->code)
            ->sum('base_amount');
    }

    private function startingBalance(Currency $base): int
    {
        $total = Money::zero($base);

        foreach (Account::query()->whereNull('archived_at')->get() as $account) {
            $total = $total->plus($this->toBase((int) $account->current_balance, (string) $account->currency, $base));
        }

        return $total->minorUnits;
    }

    /** Money that is already promised to somebody else inside the horizon. */
    private function obligations(CarbonImmutable $from, CarbonImmutable $to, Currency $base): int
    {
        $total = Money::zero($base);

        if (Schema::hasTable('checks')) {
            $checks = Check::query()
                ->where('direction', Check::DIRECTION_ISSUED)
                ->whereNotIn('status', array_merge(Check::TERMINAL_STATUSES, [Check::STATUS_CLEARED]))
                ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
                ->get();

            foreach ($checks as $check) {
                $total = $total->plus($this->toBase((int) $check->amount, (string) $check->currency, $base));
            }
        }

        if (Schema::hasTable('loan_installments')) {
            $installments = LoanInstallment::query()
                ->with('loan')
                ->where('status', '!=', LoanInstallment::STATUS_PAID)
                ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
                ->get();

            foreach ($installments as $installment) {
                $currency = (string) ($installment->loan?->currency ?? $base->code);
                $total = $total->plus($this->toBase($installment->remaining(), $currency, $base));
            }
        }

        return $total->minorUnits;
    }

    private function toBase(int $minorUnits, string $currency, Currency $base): Money
    {
        $from = Currency::of($currency);

        return $from->equals($base)
            ? Money::of($minorUnits, $base)
            : Money::of($minorUnits, $from)->convertTo($base, $this->rates->rate($from, $base));
    }
}
