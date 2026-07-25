<?php

declare(strict_types=1);

namespace Modules\MarketData\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Audit\Support\AuditRecorder;
use Modules\Ledger\Actions\ExchangeRateResolver;
use Modules\MarketData\Contracts\RateProvider;
use Modules\MarketData\Models\MarketRejection;
use Modules\MarketData\Support\RateGuard;
use Throwable;

/**
 * Fetches the configured pairs and appends whatever survives the guard.
 *
 * Two rules hold this together:
 *
 * 1. **History is appended, never rewritten.** Every fetch adds a row. A report
 *    produced last month used the rate that was current last month, and that
 *    row has to still be there for the number to be explainable.
 * 2. **A bad fetch changes nothing.** A rejected value and an unreachable feed
 *    both leave the last accepted rate in place. The ledger going one hour
 *    stale is an inconvenience; the ledger silently re-valuing every foreign
 *    balance from a decimal-point error is not.
 */
final class RefreshExchangeRates implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @param list<string>|null $quotes */
    public function __construct(
        public readonly ?string $base = null,
        public readonly ?array $quotes = null,
    ) {}

    /** @return array{stored:int, rejected:int, base:string} */
    public function handle(
        RateProvider $provider,
        RateGuard $guard,
        ExchangeRateResolver $resolver,
        AuditRecorder $audit,
    ): array {
        $base = strtoupper(trim($this->base ?? (string) config('market.base', 'USD')));
        $quotes = $this->quoteCodes($base);
        $result = ['stored' => 0, 'rejected' => 0, 'base' => $base];

        if ($quotes === []) {
            return $result;
        }

        try {
            $fetched = $provider->rates($base, $quotes);
        } catch (Throwable $e) {
            // A timeout is not an accounting event. Log it, leave every stored
            // rate exactly where it is, and let the next run try again.
            Log::warning('market: exchange rate fetch failed', [
                'provider' => $provider->name(),
                'base' => $base,
                'error' => $e->getMessage(),
            ]);

            return $result;
        }

        // Truncated because `rated_at` is a second-precision column: comparing
        // a value carrying microseconds against one read back from the table
        // would always look newer and collide with the row it was meant to
        // follow.
        $now = CarbonImmutable::now()->startOfSecond();

        foreach ($quotes as $quote) {
            if (! array_key_exists($quote, $fetched)) {
                continue;
            }

            $value = $fetched[$quote];
            $reason = $guard->reject($value, $this->lastRate($base, $quote));

            if ($reason !== null) {
                $this->recordRejection($audit, $provider->name(), $base.'>'.$quote, $value, $reason, $now);
                $result['rejected']++;

                continue;
            }

            $this->store($base, $quote, (string) $value, $provider->name(), $now);
            $result['stored']++;
        }

        if ($result['stored'] > 0) {
            $resolver->forget();
        }

        return $result;
    }

    /** @return list<string> */
    private function quoteCodes(string $base): array
    {
        $codes = $this->quotes ?? (array) config('market.quotes', []);

        $codes = array_map(static fn (mixed $code): string => strtoupper(trim((string) $code)), $codes);

        return array_values(array_unique(array_filter(
            $codes,
            static fn (string $code): bool => $code !== '' && $code !== $base,
        )));
    }

    private function lastRate(string $base, string $quote): ?string
    {
        $rate = DB::table('exchange_rates')
            ->where('base_code', $base)
            ->where('quote_code', $quote)
            ->orderByDesc('rated_at')
            ->value('rate');

        return $rate === null ? null : (string) $rate;
    }

    private function store(string $base, string $quote, string $rate, string $source, CarbonImmutable $now): void
    {
        DB::table('exchange_rates')->insert([
            'id' => (string) Str::ulid(),
            'base_code' => $base,
            'quote_code' => $quote,

            // Verbatim. Rounding here would re-price every foreign balance in
            // the product by whatever was rounded away.
            'rate' => RateGuard::decimalLiteral($rate),

            'source' => $source,
            'rated_at' => $this->nextRatedAt($base, $quote, $now),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * `exchange_rates` is unique on (base, quote, rated_at) and this table is
     * append-only, so two fetches inside the same second must not collide into
     * one row. The later one is nudged a second forward, which also keeps
     * "latest wins" — the resolver orders by rated_at — unambiguous.
     */
    private function nextRatedAt(string $base, string $quote, CarbonImmutable $now): CarbonImmutable
    {
        $last = DB::table('exchange_rates')
            ->where('base_code', $base)
            ->where('quote_code', $quote)
            ->max('rated_at');

        if ($last === null) {
            return $now;
        }

        $lastAt = CarbonImmutable::parse((string) $last);

        return $now->greaterThan($lastAt) ? $now : $lastAt->addSecond();
    }

    private function recordRejection(
        AuditRecorder $audit,
        string $provider,
        string $subject,
        mixed $value,
        string $reason,
        CarbonImmutable $now,
    ): void {
        $printable = is_scalar($value) ? substr((string) $value, 0, 128) : gettype($value);

        Log::warning('market: exchange rate rejected', [
            'provider' => $provider,
            'pair' => $subject,
            'value' => $printable,
            'reason' => $reason,
        ]);

        $rejection = MarketRejection::query()->create([
            'provider' => $provider,
            'scope' => MarketRejection::SCOPE_RATE,
            'subject' => $subject,
            'value' => $printable,
            'reason' => $reason,
            'rejected_at' => $now,
        ]);

        $audit->record('market.rate_rejected', $rejection, after: [
            'subject' => $subject,
            'value' => $printable,
            'reason' => $reason,
        ]);
    }
}
