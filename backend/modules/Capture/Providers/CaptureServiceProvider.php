<?php

declare(strict_types=1);

namespace Modules\Capture\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Capture\Support\MessageRecorder;

final class CaptureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/capture.php', 'capture');

        // Its own key, not a branch of `capture`: the pattern registry is the
        // file a deployment edits to add a bank, and it should be publishable
        // and diffable on its own.
        $this->mergeConfigFrom(__DIR__.'/../Config/sms.php', 'sms');

        $this->app->singleton(MessageRecorder::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');

        $this->publishes([
            __DIR__.'/../Config/capture.php' => config_path('capture.php'),
            __DIR__.'/../Config/sms.php' => config_path('sms.php'),
        ], 'capture-config');
    }
}
