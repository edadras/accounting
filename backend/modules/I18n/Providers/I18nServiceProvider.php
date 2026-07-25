<?php

declare(strict_types=1);

namespace Modules\I18n\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\I18n\Console\MissingTranslationsCommand;

final class I18nServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([MissingTranslationsCommand::class]);
        }
    }
}
