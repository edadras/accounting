<?php

declare(strict_types=1);

namespace Modules\Buildings\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Buildings\Queries\DebtorsReport;

final class BuildingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DebtorsReport::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
    }
}
