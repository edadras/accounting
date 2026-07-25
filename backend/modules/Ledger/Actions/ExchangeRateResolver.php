<?php

declare(strict_types=1);

namespace Modules\Ledger\Actions;

use App\Core\Money\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves "1 unit of $from buys how many units of $to", in major units.
 *
 * M1 reads the latest stored rate and falls back to the seeded table. M6
 * replaces the fallback with a scheduled provider fetch; nothing else has to
 * change because callers only ever see this interface.
 */
final class ExchangeRateResolver
{
    /** Seed rates so the ledger is usable before any provider is wired up. */
    private const FALLBACK_TO_USD = [
        'USD' => 1.0,
        'EUR' => 1.09,
        'TRY' => 0.031,
        'AED' => 0.272,
        'IRR' => 0.0000167,
        'IRT' => 0.000167,
        'USDT' => 1.0,
        'BTC' => 64000.0,
        'ETH' => 3200.0,
        'XAU' => 2350.0,
    ];

    /** @var array<string, string> */
    private array $memo = [];

    public function rate(Currency $from, Currency $to): string
    {
        if ($from->equals($to)) {
            return '1';
        }

        $key = $from->code.'>'.$to->code;

        return $this->memo[$key] ??= $this->lookup($from, $to);
    }

    private function lookup(Currency $from, Currency $to): string
    {
        $stored = $this->storedRate($from->code, $to->code);

        if ($stored !== null) {
            return $stored;
        }

        // Try the inverse before falling back, so seeding one direction is enough.
        $inverse = $this->storedRate($to->code, $from->code);

        if ($inverse !== null && (float) $inverse != 0.0) {
            return (string) (1 / (float) $inverse);
        }

        $fromUsd = self::FALLBACK_TO_USD[$from->code] ?? null;
        $toUsd = self::FALLBACK_TO_USD[$to->code] ?? null;

        if ($fromUsd === null || $toUsd === null || $toUsd == 0.0) {
            throw new \RuntimeException(
                "No exchange rate available for {$from->code} → {$to->code}."
            );
        }

        return (string) ($fromUsd / $toUsd);
    }

    private function storedRate(string $base, string $quote): ?string
    {
        if (! $this->tableExists()) {
            return null;
        }

        $rate = DB::table('exchange_rates')
            ->where('base_code', $base)
            ->where('quote_code', $quote)
            ->orderByDesc('rated_at')
            ->value('rate');

        return $rate === null ? null : (string) $rate;
    }

    private function tableExists(): bool
    {
        static $exists = null;

        return $exists ??= Schema::hasTable('exchange_rates');
    }
}
