<?php

declare(strict_types=1);

namespace Modules\Search\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Modules\Search\Contracts\SearchEngine;
use Modules\Search\Engines\DatabaseSearchEngine;
use Modules\Search\Support\SearchIndexer;

final class SearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Swapping in Meilisearch is this one line.
        $this->app->bind(SearchEngine::class, DatabaseSearchEngine::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');

        $this->keepIndexInStep();
    }

    /**
     * Search hooks into the modules it indexes from the outside, so Ledger and
     * Documents stay unaware that search exists at all.
     */
    private function keepIndexInStep(): void
    {
        foreach (SearchIndexer::sources() as [$class]) {
            $class::saved(fn (Model $model) => SearchIndexer::index($model));
            $class::deleted(fn (Model $model) => SearchIndexer::forget($model));
            $class::restored(fn (Model $model) => SearchIndexer::index($model));
        }
    }
}
