<?php

declare(strict_types=1);

namespace Modules\MarketData\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Modules\MarketData\Actions\RefreshExchangeRates;
use Modules\MarketData\Actions\RefreshInvestmentPrices;
use Modules\MarketData\Console\RefreshPricesCommand;
use Modules\MarketData\Console\RefreshRatesCommand;
use Modules\MarketData\Contracts\PriceProvider;
use Modules\MarketData\Contracts\RateProvider;
use Modules\MarketData\Exceptions\MarketDataException;
use Modules\MarketData\Gateways\HttpPriceProvider;
use Modules\MarketData\Gateways\HttpRateProvider;
use Modules\MarketData\Gateways\StaticProvider;
use Modules\MarketData\Support\RateGuard;

final class MarketDataServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/market.php', 'market');

        // With an endpoint, the feed; without one, the seed table the ledger
        // already used. The no-endpoint case is not a degraded mode — it is the
        // default, and it must leave every existing balance untouched.
        $this->app->bind(RateProvider::class, fn () => filled(config('market.rates.endpoint'))
            ? $this->app->make(HttpRateProvider::class)
            : $this->app->make(StaticProvider::class));

        $this->app->bind(PriceProvider::class, fn () => filled(config('market.prices.endpoint'))
            ? $this->app->make(HttpPriceProvider::class)
            : $this->app->make(StaticProvider::class));

        // Bound rather than shared, so the tolerance is whatever config says at
        // the moment a fetch is checked.
        $this->app->bind(RateGuard::class, fn () => new RateGuard(
            max(1.0, (float) config('market.max_change_factor', 10)),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');

        $this->publishes([
            __DIR__.'/../Config/market.php' => config_path('market.php'),
        ], 'market-config');

        if ($this->app->runningInConsole()) {
            $this->commands([RefreshRatesCommand::class, RefreshPricesCommand::class]);
        }

        $this->scheduleRefreshes();

        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if (! method_exists($handler, 'renderable')) {
                return;
            }

            $handler->renderable(fn (MarketDataException $e, Request $request) => $request->expectsJson()
                ? $e->toResponse()
                : null);
        });
    }

    /**
     * Rates hourly, prices every quarter of an hour.
     *
     * Both run on the maintenance queue (docs/02-modules.md §4) and never
     * overlap: two concurrent fetches would race to append the same instant and
     * one of them would lose to the unique index for no benefit.
     */
    private function scheduleRefreshes(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $queue = (string) config('market.queue', 'maintenance');

            $schedule->job(new RefreshExchangeRates, $queue)->hourly()->withoutOverlapping();
            $schedule->job(new RefreshInvestmentPrices, $queue)->everyFifteenMinutes()->withoutOverlapping();
        });
    }
}
