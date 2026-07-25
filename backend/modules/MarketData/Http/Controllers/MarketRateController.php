<?php

declare(strict_types=1);

namespace Modules\MarketData\Http\Controllers;

use App\Core\Money\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Support\WorkspaceContext;
use Modules\Ledger\Actions\ExchangeRateResolver;
use Modules\MarketData\Models\ExchangeRate;

final class MarketRateController
{
    public function index(
        Request $request,
        WorkspaceContext $context,
        ExchangeRateResolver $resolver,
    ): JsonResponse {
        $data = $request->validate([
            'base' => ['nullable', Rule::in(Currency::codes())],
            'quote' => ['nullable', Rule::in(Currency::codes())],
        ]);

        $base = Currency::of($data['base'] ?? (string) config('market.base', 'USD'));

        // Defaulting the quote to the workspace's own currency answers the
        // question a client actually has: what is this worth to me.
        $quote = Currency::of($data['quote'] ?? $context->baseCurrency());

        $history = ExchangeRate::query()
            ->forPair($base->code, $quote->code)
            ->orderByDesc('rated_at')
            ->limit(max(1, (int) config('market.history_limit', 30)))
            ->get();

        $latest = $history->first();

        return response()->json([
            'data' => [
                'base' => $base->code,
                'quote' => $quote->code,

                // Never absent: with nothing stored yet the resolver still has
                // an answer, and a chart with no rate is worse than a seeded
                // one that says where it came from.
                'rate' => $latest === null
                    ? $resolver->rate($base, $quote)
                    : (string) $latest->rate,

                'source' => $latest->source ?? 'fallback',
                'rated_at' => $latest?->rated_at?->toIso8601String(),
                'history' => $history->map(fn (ExchangeRate $row): array => [
                    'rate' => (string) $row->rate,
                    'source' => $row->source,
                    'rated_at' => $row->rated_at->toIso8601String(),
                ])->values(),
            ],
            'meta' => [
                'request_id' => $request->header('X-Request-Id'),
            ],
        ]);
    }
}
