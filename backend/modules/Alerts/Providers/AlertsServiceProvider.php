<?php

declare(strict_types=1);

namespace Modules\Alerts\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Modules\Alerts\Actions\DeliverDueAlerts;
use Modules\Alerts\Actions\ScanForAlerts;
use Modules\Alerts\Exceptions\AlertException;
use Modules\Alerts\Support\DeliveryLog;

final class AlertsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/alerts.php', 'alerts');

        // One recorder for the whole process, so every channel writes into the
        // same place and a test can read it back.
        $this->app->singleton(DeliveryLog::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');

        $this->publishes([
            __DIR__.'/../Config/alerts.php' => config_path('alerts.php'),
        ], 'alerts-config');

        $this->scheduleSweeps();

        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if (! method_exists($handler, 'renderable')) {
                return;
            }

            $handler->renderable(fn (AlertException $e, Request $request) => $request->expectsJson()
                ? $e->toResponse()
                : null);
        });
    }

    /**
     * Scanning hourly is enough for anything measured in days; delivery runs far
     * more often because it is what releases an alert the moment a member's
     * quiet hours end.
     */
    private function scheduleSweeps(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->job(new ScanForAlerts)->hourly()->withoutOverlapping();
            $schedule->job(new DeliverDueAlerts)->everyFiveMinutes()->withoutOverlapping();
        });
    }
}
