<?php

declare(strict_types=1);

namespace Modules\Audit\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Audit\Support\AuditRecorder;

final class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // A singleton so `withoutRecording()` can suspend the whole request.
        $this->app->singleton(AuditRecorder::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
    }
}
