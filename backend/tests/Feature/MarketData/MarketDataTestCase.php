<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData;

use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Modules\MarketData\Actions\RefreshExchangeRates;
use Modules\MarketData\Actions\RefreshInvestmentPrices;
use Modules\MarketData\Providers\MarketDataServiceProvider;
use Tests\Feature\LedgerTestCase;

abstract class MarketDataTestCase extends LedgerTestCase
{
    /**
     * Registers the module's provider for the test run.
     *
     * MarketData is not listed in bootstrap/providers.php, which this module
     * does not own and must not edit. Once it is listed, providerIsLoaded()
     * makes this a no-op.
     */
    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        if (! $app->providerIsLoaded(MarketDataServiceProvider::class)) {
            $app->register(MarketDataServiceProvider::class);
        }

        return $app;
    }

    private static bool $marketTablesMigrated = false;

    protected function setUp(): void
    {
        if (! self::$marketTablesMigrated) {
            // The suite shares one in-memory database, migrated once by
            // whichever test class ran first — and that class had no reason to
            // register this provider, so price_snapshots and market_rejections
            // are missing. One more migration run, from a class that does
            // register it, is what puts them there.
            RefreshDatabaseState::$migrated = false;
            self::$marketTablesMigrated = true;
        }

        parent::setUp();

        config([
            'market.rates.endpoint' => null,
            'market.prices.endpoint' => null,
            'market.base' => 'USD',
            'market.quotes' => ['EUR', 'TRY', 'AED', 'IRR', 'BTC'],
            'market.max_change_factor' => 10,
            'market.retries' => 1,
        ]);
    }

    /**
     * Installs $stub as the only HTTP answer.
     *
     * Http::fake() merges stubs into whatever is already registered and the
     * first match wins, so a second call would otherwise keep replying with the
     * first fixture — and every "the feed changed" test would quietly assert
     * nothing.
     *
     * @param  array<string, mixed>|Closure  $stub
     */
    protected function fakeHttp(array|Closure $stub): void
    {
        Http::swap(new Factory);
        Http::fake($stub);
    }

    /**
     * @param  list<string>|null  $quotes
     * @return array{stored:int, rejected:int, base:string}
     */
    protected function refreshRates(?string $base = null, ?array $quotes = null): array
    {
        /** @var array{stored:int, rejected:int, base:string} $result */
        $result = $this->app->call([new RefreshExchangeRates($base, $quotes), 'handle']);

        return $result;
    }

    /** @return array{updated:int, snapshots:int, rejected:int} */
    protected function refreshPrices(?string $workspaceId = null): array
    {
        /** @var array{updated:int, snapshots:int, rejected:int} $result */
        $result = $this->app->call([new RefreshInvestmentPrices($workspaceId), 'handle']);

        return $result;
    }
}
