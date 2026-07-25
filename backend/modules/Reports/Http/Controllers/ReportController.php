<?php

declare(strict_types=1);

namespace Modules\Reports\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Ledger\Models\Transaction;
use Modules\Reports\Queries\CashFlowReport;
use Modules\Reports\Queries\CategoryBreakdownReport;
use Modules\Reports\Queries\ExpenseTrendReport;
use Modules\Reports\Queries\IncomeTrendReport;
use Modules\Reports\Queries\NetWorthReport;
use Modules\Reports\Queries\TopAccountsReport;
use Modules\Reports\Queries\TopCategoriesReport;
use Modules\Reports\Queries\TopMerchantsReport;
use Modules\Reports\Support\Bucket;
use Modules\Reports\Support\DateRange;

/**
 * One endpoint for every report: GET /api/v1/reports/{type}.
 *
 * `{type}` is checked against a fixed list and then dispatched through a
 * `match`. It never reaches a class name, a container binding or a query — the
 * whole point is that no request can name a class or a column.
 */
final class ReportController
{
    /** @var list<string> */
    private const TYPES = [
        'cash-flow',
        'net-worth',
        'expense-trend',
        'income-trend',
        'top-categories',
        'top-merchants',
        'top-accounts',
        'category-breakdown',
    ];

    public function show(Request $request, string $type): JsonResponse
    {
        if (! in_array($type, self::TYPES, true)) {
            return response()->json([
                'error' => [
                    'code' => 'unknown_report',
                    'message' => 'Unknown report type.',
                    'allowed' => self::TYPES,
                    'request_id' => $request->header('X-Request-Id'),
                ],
            ], 404);
        }

        $input = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'bucket' => ['nullable', Rule::in(Bucket::values())],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'depth' => ['nullable', 'integer', 'min:1', 'max:10'],
            'flow' => ['nullable', Rule::in([Transaction::TYPE_INCOME, Transaction::TYPE_EXPENSE])],
        ]);

        $range = DateRange::parse($input['from'] ?? null, $input['to'] ?? null);
        $bucket = Bucket::parse($input['bucket'] ?? null);
        $limit = (int) ($input['limit'] ?? 10);
        $depth = (int) ($input['depth'] ?? 1);
        $flow = (string) ($input['flow'] ?? Transaction::TYPE_EXPENSE);

        $data = match ($type) {
            'cash-flow' => app(CashFlowReport::class)->handle($range, $bucket),
            'net-worth' => app(NetWorthReport::class)->handle($range, $bucket),
            'expense-trend' => app(ExpenseTrendReport::class)->handle($range, $bucket),
            'income-trend' => app(IncomeTrendReport::class)->handle($range, $bucket),
            'top-categories' => app(TopCategoriesReport::class)->handle($range, $limit, $depth, $flow),
            'top-merchants' => app(TopMerchantsReport::class)->handle($range, $limit, $flow),
            'top-accounts' => app(TopAccountsReport::class)->handle($range, $limit, $flow),
            'category-breakdown' => app(CategoryBreakdownReport::class)->handle($range, $limit, $depth, $flow),
        };

        return response()->json(['data' => $data]);
    }

    /** What this endpoint can be asked for — so the client never hardcodes it. */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                'types' => self::TYPES,
                'buckets' => Bucket::values(),
            ],
        ]);
    }
}
