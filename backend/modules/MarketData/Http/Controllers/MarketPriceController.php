<?php

declare(strict_types=1);

namespace Modules\MarketData\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Investment\Http\Resources\InvestmentResource;
use Modules\Investment\Models\Investment;
use Modules\MarketData\Exceptions\MarketDataException;
use Modules\MarketData\Models\PriceSnapshot;

final class MarketPriceController
{
    public function show(Request $request, string $symbol): JsonResponse
    {
        $symbol = strtoupper(trim($symbol));

        $history = PriceSnapshot::query()
            ->where('symbol', $symbol)
            ->orderByDesc('captured_at')
            ->limit(max(1, (int) config('market.history_limit', 30)))
            ->get();

        // Scoped by BelongsToWorkspace: the price series is public market data,
        // but what this caller holds of it is not, and the two travel in the
        // same response.
        $positions = Investment::query()
            ->where('symbol', $symbol)
            ->orderBy('name')
            ->get();

        if ($history->isEmpty() && $positions->isEmpty()) {
            throw MarketDataException::unknownSymbol($symbol);
        }

        $latest = $history->first();

        return response()->json([
            'data' => [
                'symbol' => $symbol,
                'kind' => $latest?->kind,
                'price' => $latest === null ? null : (string) $latest->price,
                'currency' => $latest?->currency,
                'source' => $latest?->source,
                'captured_at' => $latest?->captured_at?->toIso8601String(),
                'history' => $history->map(fn (PriceSnapshot $row): array => [
                    'kind' => $row->kind,
                    'price' => (string) $row->price,
                    'currency' => $row->currency,
                    'source' => $row->source,
                    'captured_at' => $row->captured_at->toIso8601String(),
                ])->values(),
                'positions' => InvestmentResource::collection($positions),
            ],
            'meta' => [
                'request_id' => $request->header('X-Request-Id'),
            ],
        ]);
    }
}
