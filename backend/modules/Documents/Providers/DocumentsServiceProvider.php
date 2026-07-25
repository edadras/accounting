<?php

declare(strict_types=1);

namespace Modules\Documents\Providers;

use Illuminate\Support\ServiceProvider;

final class DocumentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/documents.php', 'documents');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');

        $this->publishes([
            __DIR__.'/../Config/documents.php' => config_path('documents.php'),
        ], 'documents-config');
    }
}
