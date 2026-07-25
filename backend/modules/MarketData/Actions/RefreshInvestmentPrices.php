<?php

declare(strict_types=1);

namespace Modules\MarketData\Actions;

use App\Core\Money\Currency;
use App\Core\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Audit\Support\AuditRecorder;
use Modules\Core\Models\Workspace;
use Modules\Core\Support\WorkspaceContext;
use Modules\Investment\Models\Investment;
use Modules\Ledger\Actions\ExchangeRateResolver;
use Modules\MarketData\Contracts\PriceProvider;
use Modules\MarketData\Models\MarketRejection;
use Modules\MarketData\Models\PriceSnapshot;
use Modules\MarketData\Support\PriceQuote;
use Modules\MarketData\Support\RateGuard;
use Throwable;

/**
 * Puts today's price on every open position and keeps the series behind it.
 *
 * Only symbols somebody actually holds are asked for, so the feed is never
 * polled for a catalogue nobody owns. The snapshot is written in the currency
 * the feed quoted; the conversion into each position's own currency happens per
 * position, because two workspaces can hold the same symbol in different money.
 */
final class RefreshInvestmentPrices implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly ?string $workspaceId = null) {}

    /** @return array{updated:int, snapshots:int, rejected:int} */
    public function handle(
        PriceProvider $provider,
        RateGuard $guard,
        ExchangeRateResolver $rates,
        AuditRecorder $audit,
        WorkspaceContext $context,
    ): array {
        $result = ['updated' => 0, 'snapshots' => 0, 'rejected' => 0];
        $holdings = $this->holdings();

        if ($holdings === []) {
            return $result;
        }

        $symbols = array_values(array_unique(array_column($holdings, 'symbol')));

        try {
            $quotes = $provider->prices($symbols);
        } catch (Throwable $e) {
            Log::warning('market: price fetch failed', [
                'provider' => $provider->name(),
                'error' => $e->getMessage(),
            ]);

            return $result;
        }

        // Truncated for the same reason the rates table needs it: `captured_at`
        // holds whole seconds, so a value with microseconds would compare as
        // newer than the row it collides with.
        $now = CarbonImmutable::now()->startOfSecond();
        $accepted = [];

        foreach ($quotes as $symbol => $quote) {
            $symbol = strtoupper((string) $symbol);
            $reason = $guard->reject($quote->price, $this->lastPrice($symbol));

            if ($reason !== null) {
                $this->recordRejection($audit, $provider->name(), $symbol, $quote->price, $reason, $now);
                $result['rejected']++;

                continue;
            }

            $accepted[$symbol] = $quote;
        }

        if ($accepted === []) {
            return $result;
        }

        $result['snapshots'] = $this->snapshot($accepted, $holdings, $provider->name(), $now);
        $result['updated'] = $this->reprice($accepted, $holdings, $rates, $context, $now);

        return $result;
    }

    /**
     * The distinct (workspace, symbol, kind) triples currently held.
     *
     * Read straight from the table: this job runs for every tenant at once, and
     * the workspace scope has no meaning — or safe answer — outside one.
     *
     * @return list<array{workspace_id:string, symbol:string, kind:string}>
     */
    private function holdings(): array
    {
        $rows = DB::table('investments')
            ->whereNull('deleted_at')
            ->whereNotNull('symbol')
            ->where('symbol', '<>', '')
            ->when($this->workspaceId !== null, fn ($query) => $query->where('workspace_id', $this->workspaceId))
            ->select('workspace_id', 'symbol', 'kind')
            ->distinct()
            ->get();

        $holdings = [];

        foreach ($rows as $row) {
            $holdings[] = [
                'workspace_id' => (string) $row->workspace_id,
                'symbol' => strtoupper(trim((string) $row->symbol)),
                'kind' => (string) $row->kind,
            ];
        }

        return $holdings;
    }

    /**
     * @param  array<string, PriceQuote>  $accepted
     * @param  list<array{workspace_id:string, symbol:string, kind:string}>  $holdings
     */
    private function snapshot(array $accepted, array $holdings, string $source, CarbonImmutable $now): int
    {
        $pairs = [];

        foreach ($holdings as $holding) {
            $quote = $accepted[$holding['symbol']] ?? null;

            if ($quote === null) {
                continue;
            }

            // The feed's own classification wins when it offers one; otherwise
            // the series is filed under the kind the holders gave it.
            $kind = $quote->kind ?? $holding['kind'];
            $pairs[$holding['symbol'].'|'.$kind] = [$holding['symbol'], $kind, $quote];
        }

        $written = 0;

        foreach ($pairs as [$symbol, $kind, $quote]) {
            PriceSnapshot::query()->create([
                'symbol' => $symbol,
                'kind' => $kind,
                'price' => RateGuard::decimalLiteral($quote->price),
                'currency' => $quote->currency,
                'source' => $source,
                'captured_at' => $this->nextCapturedAt($symbol, $kind, $now),
            ]);

            $written++;
        }

        return $written;
    }

    /**
     * @param  array<string, PriceQuote>  $accepted
     * @param  list<array{workspace_id:string, symbol:string, kind:string}>  $holdings
     */
    private function reprice(
        array $accepted,
        array $holdings,
        ExchangeRateResolver $rates,
        WorkspaceContext $context,
        CarbonImmutable $now,
    ): int {
        $updated = 0;

        foreach (array_unique(array_column($holdings, 'workspace_id')) as $workspaceId) {
            $workspace = Workspace::query()->find($workspaceId);

            if ($workspace === null) {
                continue;
            }

            $updated += (int) $context->runFor($workspace, function () use ($accepted, $rates, $now): int {
                $count = 0;

                foreach (Investment::query()->whereNotNull('symbol')->cursor() as $investment) {
                    $quote = $accepted[strtoupper(trim((string) $investment->symbol))] ?? null;

                    if ($quote === null) {
                        continue;
                    }

                    $price = $this->priceIn($quote, $investment->priceCurrency(), $rates);

                    if ($price === null) {
                        continue;
                    }

                    $investment->forceFill([
                        'current_price' => $price->minorUnits,
                        'priced_at' => $now,
                    ])->save();

                    $count++;
                }

                return $count;
            });
        }

        return $updated;
    }

    private function priceIn(PriceQuote $quote, Currency $target, ExchangeRateResolver $rates): ?Money
    {
        try {
            $source = Currency::of($quote->currency);

            // Rounded to the quote currency's own precision, which is the most
            // a price can mean in that currency; the wider snapshot row keeps
            // whatever the feed sent.
            $money = Money::fromDecimalString(
                number_format((float) $quote->price, $source->minorUnit, '.', ''),
                $source,
            );

            return $source->equals($target)
                ? $money
                : $money->convertTo($target, $rates->rate($source, $target));
        } catch (Throwable $e) {
            Log::warning('market: price could not be converted', [
                'symbol' => $quote->symbol,
                'from' => $quote->currency,
                'to' => $target->code,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function lastPrice(string $symbol): ?string
    {
        $price = DB::table('price_snapshots')
            ->where('symbol', $symbol)
            ->orderByDesc('captured_at')
            ->value('price');

        return $price === null ? null : (string) $price;
    }

    /** Same reason as the rates table: the series is appended, never replaced. */
    private function nextCapturedAt(string $symbol, string $kind, CarbonImmutable $now): CarbonImmutable
    {
        $last = DB::table('price_snapshots')
            ->where('symbol', $symbol)
            ->where('kind', $kind)
            ->max('captured_at');

        if ($last === null) {
            return $now;
        }

        $lastAt = CarbonImmutable::parse((string) $last);

        return $now->greaterThan($lastAt) ? $now : $lastAt->addSecond();
    }

    private function recordRejection(
        AuditRecorder $audit,
        string $provider,
        string $symbol,
        string $value,
        string $reason,
        CarbonImmutable $now,
    ): void {
        Log::warning('market: price rejected', [
            'provider' => $provider,
            'symbol' => $symbol,
            'value' => $value,
            'reason' => $reason,
        ]);

        $rejection = MarketRejection::query()->create([
            'provider' => $provider,
            'scope' => MarketRejection::SCOPE_PRICE,
            'subject' => $symbol,
            'value' => substr($value, 0, 128),
            'reason' => $reason,
            'rejected_at' => $now,
        ]);

        $audit->record('market.price_rejected', $rejection, after: [
            'symbol' => $symbol,
            'value' => substr($value, 0, 128),
            'reason' => $reason,
        ]);
    }
}
