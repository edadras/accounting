<?php

declare(strict_types=1);

namespace Modules\Payroll\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Payroll owns its migrations, routes and config; installing it is one line in
 * bootstrap/providers.php and nothing else.
 *
 * There is no exception renderer to register: PayrollException implements
 * Responsable, so the framework renders it without a line in
 * bootstrap/app.php.
 */
final class PayrollServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/payroll.php', 'payroll');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');

        $this->publishes([
            __DIR__.'/../Config/payroll.php' => config_path('payroll.php'),
        ], 'payroll-config');
    }
}
