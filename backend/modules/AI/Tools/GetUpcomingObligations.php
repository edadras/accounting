<?php

declare(strict_types=1);

namespace Modules\AI\Tools;

use App\Core\Money\Currency;
use App\Core\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Modules\AI\Contracts\Tool;
use Modules\Banking\Models\Check;
use Modules\Banking\Models\LoanInstallment;
use Modules\Core\Support\WorkspaceContext;
use Modules\Ledger\Actions\ExchangeRateResolver;

/**
 * Cheques and instalments falling due, including the ones already overdue —
 * "قبض‌های عقب‌افتاده را نشان بده" is one of the v1 target questions.
 */
final class GetUpcomingObligations implements Tool
{
    public function __construct(
        private readonly WorkspaceContext $context,
        private readonly ExchangeRateResolver $rates,
    ) {}

    public function name(): string
    {
        return 'get_upcoming_obligations';
    }

    public function description(): string
    {
        return 'Cheques and loan instalments due within the next N days, overdue ones included.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365],
                'include_overdue' => ['type' => 'boolean'],
            ],
        ];
    }

    public function run(array $arguments): array
    {
        $days = max(1, min(365, (int) ($arguments['days'] ?? 30)));
        $includeOverdue = (bool) ($arguments['include_overdue'] ?? true);

        $now = CarbonImmutable::now()->startOfDay();
        $from = $includeOverdue ? $now->subYear() : $now;
        $to = $now->addDays($days);

        $base = Currency::of($this->context->baseCurrency());
        $total = Money::zero($base);
        $rows = [];

        if (Schema::hasTable('checks')) {
            $checks = Check::query()
                ->whereNotIn('status', array_merge(Check::TERMINAL_STATUSES, [Check::STATUS_CLEARED]))
                ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
                ->orderBy('due_date')
                ->get();

            foreach ($checks as $check) {
                $rows[] = [
                    'kind' => 'check',
                    'id' => $check->id,
                    'title' => trim(($check->party_name ?? '').' #'.$check->check_number),
                    'direction' => $check->direction,
                    'due_date' => $check->due_date->toDateString(),
                    'overdue' => $check->due_date->lessThan($now),
                    'amount' => (int) $check->amount,
                    'currency' => (string) $check->currency,
                ];

                if ($check->direction === Check::DIRECTION_ISSUED) {
                    $total = $total->plus($this->toBase((int) $check->amount, (string) $check->currency, $base));
                }
            }
        }

        if (Schema::hasTable('loan_installments')) {
            $installments = LoanInstallment::query()
                ->with('loan')
                ->where('status', '!=', LoanInstallment::STATUS_PAID)
                ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
                ->orderBy('due_date')
                ->get();

            foreach ($installments as $installment) {
                $currency = (string) ($installment->loan->currency ?? $base->code);
                $remaining = $installment->remaining();

                $rows[] = [
                    'kind' => 'installment',
                    'id' => $installment->id,
                    'title' => trim((string) ($installment->loan->title ?? 'loan')).' #'.$installment->number,
                    'direction' => 'issued',
                    'due_date' => $installment->due_date->toDateString(),
                    'overdue' => $installment->due_date->lessThan($now),
                    'amount' => $remaining,
                    'currency' => $currency,
                ];

                $total = $total->plus($this->toBase($remaining, $currency, $base));
            }
        }

        usort($rows, static fn (array $a, array $b) => $a['due_date'] <=> $b['due_date']);

        return [
            'days' => $days,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'currency' => $base->code,
            'total_outflow' => $total->minorUnits,
            'count' => count($rows),
            'obligations' => $rows,
        ];
    }

    private function toBase(int $minorUnits, string $currency, Currency $base): Money
    {
        $from = Currency::of($currency);

        return $from->equals($base)
            ? Money::of($minorUnits, $base)
            : Money::of($minorUnits, $from)->convertTo($base, $this->rates->rate($from, $base));
    }
}
