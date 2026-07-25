<?php

declare(strict_types=1);

namespace Modules\Sync\Providers;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Modules\Sync\Exceptions\SyncException;
use Modules\Sync\Support\SyncRegistry;

final class SyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/sync.php', 'sync');

        $this->app->singleton(SyncRegistry::class, static fn (): SyncRegistry => SyncRegistry::fromConfig());
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');

        $this->publishes([
            __DIR__.'/../Config/sync.php' => config_path('sync.php'),
        ], 'sync-config');

        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if (! method_exists($handler, 'renderable')) {
                return;
            }

            $handler->renderable(fn (SyncException $e, Request $request) => $request->expectsJson()
                ? $e->toResponse()
                : null);
        });
    }
}
