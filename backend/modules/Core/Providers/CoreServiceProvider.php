<?php

declare(strict_types=1);

namespace Modules\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Core\Support\WorkspaceContext;

final class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One context per request; the workspace scope reads it on every query.
        $this->app->singleton(WorkspaceContext::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }
}
