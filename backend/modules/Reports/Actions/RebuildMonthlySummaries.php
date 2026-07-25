<?php

declare(strict_types=1);

namespace Modules\Reports\Actions;

use App\Core\Money\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\WorkspaceContext;
use Modules\Ledger\Models\Transaction;
use Modules\Reports\Models\MonthlySummary;

/**
 * Recomputes the monthly aggregation cache for the active workspace.
 *
 * A period is deleted and rewritten rather than updated in place: a partial
 * update leaves a row that looks current but is not, and a stale total is worse
 * than a missing one because nothing downstream can tell.
 */
final readonly class RebuildMonthlySummaries
{
    public function __construct(private WorkspaceContext $context) {}

    /**
     * @param  list<string>|null  $periodKeys  Months to rebuild ('2026-07'); null rebuilds every month with activity.
     * @return int Number of summary rows written.
     */
    public function handle(?array $periodKeys = null): int
    {
        $currency = Currency::of($this->context->baseCurrency());

        $rows = Transaction::query()
            ->whereIn('type', [Transaction::TYPE_INCOME, Transaction::TYPE_EXPENSE])
            ->where('base_currency', $currency->code)
            ->selectRaw('DATE(occurred_at) as day, type, category_id, account_id, SUM(base_amount) as total, COUNT(*) as transactions')
            ->groupByRaw('DATE(occurred_at), type, category_id, account_id')
            ->get();

        /** @var array<string, array<string, array{income:int,expense:int,count:int,category_id:?string,account_id:?string}>> $buckets */
        $buckets = [];

        foreach ($rows as $row) {
            $period = CarbonImmutable::parse((string) $row->getAttribute('day'))->format('Y-m');

            if ($periodKeys !== null && ! in_array($period, $periodKeys, true)) {
                continue;
            }

            $categoryId = $row->getAttribute('category_id');
            $accountId = $row->getAttribute('account_id');
            $isIncome = $row->getAttribute('type') === Transaction::TYPE_INCOME;
            $total = (int) $row->getAttribute('total');
            $count = (int) $row->getAttribute('transactions');

            // Three grains from one scan: the whole workspace, per category and
            // per account. The dashboard reads the first, reports read the rest.
            //
            // A category grain is only written when there is a category. An
            // uncategorized transaction would otherwise produce a row that is
            // indistinguishable from the workspace-wide one and get counted
            // into it twice.
            $grains = [['w', null, null]];

            if ($categoryId !== null) {
                $grains[] = ['c:'.$categoryId, (string) $categoryId, null];
            }

            if ($accountId !== null) {
                $grains[] = ['a:'.$accountId, null, (string) $accountId];
            }

            foreach ($grains as [$key, $category, $account]) {
                $buckets[$period][$key] ??= [
                    'income' => 0,
                    'expense' => 0,
                    'count' => 0,
                    'category_id' => $category,
                    'account_id' => $account,
                ];

                $buckets[$period][$key][$isIncome ? 'income' : 'expense'] += $total;
                $buckets[$period][$key]['count'] += $count;
            }
        }

        $periods = $periodKeys ?? array_keys($buckets);
        $written = 0;

        DB::transaction(function () use ($periods, $buckets, $currency, &$written): void {
            foreach ($periods as $period) {
                MonthlySummary::query()->forPeriod($period)->delete();

                foreach ($buckets[$period] ?? [] as $grain) {
                    MonthlySummary::query()->create([
                        'period_key' => $period,
                        'category_id' => $grain['category_id'],
                        'account_id' => $grain['account_id'],
                        'income_amount' => $grain['income'],
                        'expense_amount' => $grain['expense'],
                        'currency' => $currency->code,
                        'transaction_count' => $grain['count'],
                    ]);

                    $written++;
                }
            }
        });

        return $written;
    }
}
