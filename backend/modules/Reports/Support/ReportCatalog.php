<?php

declare(strict_types=1);

namespace Modules\Reports\Support;

use Modules\Reports\Queries\CashFlowReport;
use Modules\Reports\Queries\CategoryBreakdownReport;
use Modules\Reports\Queries\ExpenseTrendReport;
use Modules\Reports\Queries\IncomeTrendReport;
use Modules\Reports\Queries\NetWorthReport;
use Modules\Reports\Queries\TopAccountsReport;
use Modules\Reports\Queries\TopCategoriesReport;
use Modules\Reports\Queries\TopMerchantsReport;

/**
 * The one list of report types, and the one `match` that runs them.
 *
 * `{type}` arrives from a URL. It is checked against a fixed list and then
 * dispatched through a `match`; it never reaches a class name, a container
 * binding or a query. The JSON endpoint and the export endpoint share this
 * class precisely so there is one whitelist to keep honest rather than two.
 */
final class ReportCatalog
{
    /** @var list<string> */
    public const TYPES = [
        'cash-flow',
        'net-worth',
        'expense-trend',
        'income-trend',
        'top-categories',
        'top-merchants',
        'top-accounts',
        'category-breakdown',
    ];

    public static function supports(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /** @return array<string, mixed> */
    public function run(string $type, ReportFilters $filters): array
    {
        return match ($type) {
            'cash-flow' => app(CashFlowReport::class)->handle($filters->range, $filters->bucket),
            'net-worth' => app(NetWorthReport::class)->handle($filters->range, $filters->bucket),
            'expense-trend' => app(ExpenseTrendReport::class)->handle($filters->range, $filters->bucket),
            'income-trend' => app(IncomeTrendReport::class)->handle($filters->range, $filters->bucket),
            'top-categories' => app(TopCategoriesReport::class)->handle($filters->range, $filters->limit, $filters->depth, $filters->flow),
            'top-merchants' => app(TopMerchantsReport::class)->handle($filters->range, $filters->limit, $filters->flow),
            'top-accounts' => app(TopAccountsReport::class)->handle($filters->range, $filters->limit, $filters->flow),
            'category-breakdown' => app(CategoryBreakdownReport::class)->handle($filters->range, $filters->limit, $filters->depth, $filters->flow),
            default => throw new \InvalidArgumentException("Unknown report type [{$type}]."),
        };
    }

    /**
     * How many rows the export will have, without running anything.
     *
     * A period report has exactly one row per bucket in the range, and a
     * top-N report has at most `limit` — both are known from the request. That
     * is what lets the inline-versus-queued decision be made before the first
     * query rather than after the expensive part is already done.
     */
    public function estimatedRows(string $type, ReportFilters $filters): int
    {
        return match ($type) {
            'cash-flow', 'net-worth', 'expense-trend', 'income-trend' => count(
                $filters->bucket->periodsFor($filters->range),
            ),
            // One more than the limit: whatever the list leaves out is folded
            // into a final "other" row rather than dropped.
            'category-breakdown' => $filters->limit + 1,
            default => $filters->limit,
        };
    }
}
