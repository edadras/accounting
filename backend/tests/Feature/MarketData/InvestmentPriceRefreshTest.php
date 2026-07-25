<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData;

use App\Core\Money\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\Core\Models\Workspace;
use Modules\Investment\Models\Investment;
use Modules\Ledger\Actions\ExchangeRateResolver;
use Modules\MarketData\Models\MarketRejection;
use Modules\MarketData\Models\PriceSnapshot;
use Modules\MarketData\Support\RateGuard;
use PHPUnit\Framework\Attributes\Test;

/**
 * Prices landing on open positions, and the series left behind for a chart.
 */
final class InvestmentPriceRefreshTest extends MarketDataTestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://prices.test/quotes';

    #[Test]
    public function a_price_updates_the_position_and_writes_a_snapshot(): void
    {
        $workspace = $this->makeWorkspace($this->makeUser('holder@example.test'), currency: 'TRY');
        $bitcoin = $this->makePosition($workspace, 'Bitcoin', 'crypto', 'BTC', 'USD');

        $this->useFeed([['symbol' => 'BTC', 'price' => '64000.10', 'currency' => 'USD', 'kind' => 'crypto']]);

        $result = $this->refreshPrices();

        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $result['snapshots']);

        $bitcoin->refresh();

        // $64,000.10 in minor units, in the position's own currency.
        $this->assertSame(6_400_010, (int) $bitcoin->current_price);
        $this->assertNotNull($bitcoin->priced_at);

        $snapshot = PriceSnapshot::query()->sole();

        $this->assertSame('BTC', $snapshot->symbol);
        $this->assertSame('crypto', $snapshot->kind);
        $this->assertSame('USD', $snapshot->currency);
        $this->assertSame(64000.10, (float) $snapshot->price);
    }

    #[Test]
    public function a_price_quoted_in_another_currency_lands_converted(): void
    {
        $workspace = $this->makeWorkspace($this->makeUser('gold@example.test'), currency: 'TRY');
        $gold = $this->makePosition($workspace, 'Gold', 'gold', 'XAU', 'TRY');

        $this->useFeed([['symbol' => 'XAU', 'price' => '2350.00', 'currency' => 'USD', 'kind' => 'gold']]);

        $this->assertSame(1, $this->refreshPrices()['updated']);

        $rate = app(ExchangeRateResolver::class)->rate(Currency::of('USD'), Currency::of('TRY'));
        $expected = (int) floor(235000 * (float) $rate + 0.5);

        $this->assertSame($expected, (int) $gold->refresh()->current_price);

        // The series keeps what the feed said, not what one holder's currency
        // made of it.
        $this->assertSame('USD', PriceSnapshot::query()->sole()->currency);
    }

    #[Test]
    public function each_refresh_appends_to_the_series(): void
    {
        $workspace = $this->makeWorkspace($this->makeUser('chart@example.test'), currency: 'USD');
        $this->makePosition($workspace, 'Ethereum', 'crypto', 'ETH', 'USD');

        $this->useFeed([['symbol' => 'ETH', 'price' => '3200.00', 'currency' => 'USD']]);
        $this->refreshPrices();

        $this->useFeed([['symbol' => 'ETH', 'price' => '3300.00', 'currency' => 'USD']]);
        $this->refreshPrices();

        $series = PriceSnapshot::query()->where('symbol', 'ETH')->orderBy('captured_at')->get();

        $this->assertCount(2, $series);
        $this->assertSame([3200.0, 3300.0], $series->map(fn ($row) => (float) $row->price)->all());
    }

    #[Test]
    public function an_absurd_price_is_rejected_and_the_position_keeps_the_last_one(): void
    {
        $workspace = $this->makeWorkspace($this->makeUser('absurd@example.test'), currency: 'USD');
        $bitcoin = $this->makePosition($workspace, 'Bitcoin', 'crypto', 'BTC', 'USD');

        $this->useFeed([['symbol' => 'BTC', 'price' => '64000.00', 'currency' => 'USD']]);
        $this->refreshPrices();

        $this->useFeed([['symbol' => 'BTC', 'price' => '0', 'currency' => 'USD']]);
        $result = $this->refreshPrices();

        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['rejected']);
        $this->assertSame(6_400_000, (int) $bitcoin->refresh()->current_price);
        $this->assertSame(RateGuard::NOT_POSITIVE, MarketRejection::query()->sole()->reason);
        $this->assertCount(1, PriceSnapshot::query()->get());
    }

    #[Test]
    public function a_timeout_leaves_every_position_untouched(): void
    {
        $workspace = $this->makeWorkspace($this->makeUser('offline@example.test'), currency: 'USD');
        $bitcoin = $this->makePosition($workspace, 'Bitcoin', 'crypto', 'BTC', 'USD');

        $this->useFeed([['symbol' => 'BTC', 'price' => '64000.00', 'currency' => 'USD']]);
        $this->refreshPrices();

        config(['market.prices.endpoint' => self::ENDPOINT]);
        $this->fakeHttp(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $this->assertSame(0, $this->refreshPrices()['updated']);
        $this->assertSame(6_400_000, (int) $bitcoin->refresh()->current_price);
        $this->assertCount(1, PriceSnapshot::query()->get());
    }

    #[Test]
    public function one_workspace_can_be_repriced_without_touching_the_others(): void
    {
        $mine = $this->makeWorkspace($this->makeUser('mine@example.test'), currency: 'USD');
        $theirs = $this->makeWorkspace($this->makeUser('theirs@example.test'), currency: 'USD');

        $ours = $this->makePosition($mine, 'Bitcoin', 'crypto', 'BTC', 'USD');
        $others = $this->makePosition($theirs, 'Bitcoin', 'crypto', 'BTC', 'USD');

        $this->useFeed([['symbol' => 'BTC', 'price' => '64000.00', 'currency' => 'USD']]);

        $this->assertSame(1, $this->refreshPrices($mine->id)['updated']);

        $this->assertSame(6_400_000, (int) $ours->refresh()->current_price);
        $this->assertNull($others->refresh()->current_price);
    }

    /** @param list<array<string, mixed>> $prices */
    private function useFeed(array $prices): void
    {
        config(['market.prices.endpoint' => self::ENDPOINT]);

        $this->fakeHttp(['*' => Http::response(['prices' => $prices])]);
    }

    private function makePosition(
        Workspace $workspace,
        string $name,
        string $kind,
        string $symbol,
        string $currency,
    ): Investment {
        return $this->inWorkspace($workspace, fn () => Investment::query()->create([
            'name' => $name,
            'kind' => $kind,
            'symbol' => $symbol,
            'currency' => $currency,
            'quantity' => '1.00000000',
            'avg_buy_price' => 1_000_000,
        ]));
    }
}
