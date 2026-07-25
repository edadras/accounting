<?php

declare(strict_types=1);

namespace Modules\Travel\Providers;

use Illuminate\Support\ServiceProvider;

final class TravelServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
    }
}
