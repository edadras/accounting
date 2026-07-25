<?php

declare(strict_types=1);

namespace Modules\Search\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Modules\Search\Console\EmbedCommand;
use Modules\Search\Console\ReindexCommand;
use Modules\Search\Contracts\SearchEngine;
use Modules\Search\Engines\DatabaseSearchEngine;
use Modules\Search\Engines\MeilisearchEngine;
use Modules\Search\Support\SearchIndexer;

final class SearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/search.php', 'search');

        // The database engine stays the default, and stays the thing that
        // works with nothing installed and nothing running. Meilisearch is
        // opt-in per deployment, and because both sit behind the same
        // contract, nothing but this line knows which one answered.
        $this->app->bind(SearchEngine::class, fn () => config('search.driver') === 'meilisearch'
            ? $this->app->make(MeilisearchEngine::class)
            : $this->app->make(DatabaseSearchEngine::class));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');

        $this->publishes([
            __DIR__.'/../Config/search.php' => config_path('search.php'),
        ], 'search-config');

        if ($this->app->runningInConsole()) {
            $this->commands([ReindexCommand::class, EmbedCommand::class]);
        }

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
