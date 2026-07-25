<?php

declare(strict_types=1);

namespace Modules\DataOps\Providers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\DataOps\Console\PurgeAccountsCommand;
use Modules\DataOps\Console\VerifyBackupCommand;
use Modules\DataOps\Http\Middleware\RefuseScheduledAccounts;

final class DataOpsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/dataops.php', 'dataops');

        $this->applyBackupConfig();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');

        // Sign-in is declared in routes/api.php, which this module does not
        // own; the group is where the check can be added from here.
        Route::pushMiddlewareToGroup('api', RefuseScheduledAccounts::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                PurgeAccountsCommand::class,
                VerifyBackupCommand::class,
            ]);
        }

        $this->publishes([
            __DIR__.'/../Config/dataops.php' => config_path('dataops.php'),
        ], 'dataops-config');
    }

    /**
     * Lays the module's backup settings over the package's defaults.
     *
     * `mergeConfigFrom` would lose here: it keeps whatever value is already
     * present for a key, and spatie's own provider has published the whole
     * `backup` tree before this one runs. The module's file is the one that has
     * to win, so it is replaced over those defaults rather than merged under
     * them — and only the keys Finora actually changes need to be listed.
     */
    private function applyBackupConfig(): void
    {
        $config = $this->app->make(Repository::class);

        $config->set('backup', array_replace_recursive(
            (array) $config->get('backup', []),
            require __DIR__.'/../Config/backup.php',
        ));
    }
}
