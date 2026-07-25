<?php

declare(strict_types=1);

namespace Modules\Banking\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Banking\Actions\GenerateAmortizationSchedule;

final class BankingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GenerateAmortizationSchedule::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
    }
}
