<?php

declare(strict_types=1);

namespace Modules\Reports\Providers;

use Illuminate\Support\ServiceProvider;

final class ReportsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
    }
}
