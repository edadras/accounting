<?php

declare(strict_types=1);

namespace Modules\Billing\Providers;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Modules\Billing\Console\SyncPlansCommand;
use Modules\Billing\Contracts\PaymentGateway;
use Modules\Billing\Exceptions\BillingException;
use Modules\Billing\Gateways\FakeGateway;
use Modules\Billing\Http\Middleware\RequiresEntitlement;
use Modules\Billing\Support\UsageCounter;

final class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/plans.php', 'plans');

        $this->app->singleton(UsageCounter::class);

        // A singleton so a test can resolve the gateway and inspect what it was
        // asked to charge. Pointing this at a real processor is one line.
        $this->app->singleton(FakeGateway::class);
        $this->app->bind(PaymentGateway::class, FakeGateway::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        // Registered before the routes load, so any module may gate a route with
        // `->middleware('entitlement:ai')` and get its answer from Entitlements.
        $this->app->make(Router::class)->aliasMiddleware('entitlement', RequiresEntitlement::class);

        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');

        $this->publishes([
            __DIR__.'/../Config/plans.php' => config_path('plans.php'),
        ], 'billing-config');

        if ($this->app->runningInConsole()) {
            $this->commands([SyncPlansCommand::class]);
        }

        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if (! method_exists($handler, 'renderable')) {
                return;
            }

            $handler->renderable(fn (BillingException $e, Request $request) => $request->expectsJson()
                ? $e->toResponse()
                : null);
        });
    }
}
