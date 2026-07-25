<?php

declare(strict_types=1);

namespace Modules\AI\Providers;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Modules\AI\Contracts\AiProvider;
use Modules\AI\Contracts\EmbeddingProvider;
use Modules\AI\Contracts\Tool;
use Modules\AI\Exceptions\AiException;
use Modules\AI\Providers\Gateway\DeterministicProvider;
use Modules\AI\Providers\Gateway\HttpEmbeddingProvider;
use Modules\AI\Providers\Gateway\LocalEmbeddingProvider;
use Modules\AI\Providers\Gateway\OpenAiProvider;
use Modules\AI\Support\AudioNormalizer;
use Modules\AI\Tools\CreateBudgetDraft;
use Modules\AI\Tools\GetAccountBalances;
use Modules\AI\Tools\GetBudgetStatus;
use Modules\AI\Tools\GetCashflowForecast;
use Modules\AI\Tools\GetInvestmentPerformance;
use Modules\AI\Tools\GetSpendingSummary;
use Modules\AI\Tools\GetTransactions;
use Modules\AI\Tools\GetUpcomingObligations;
use Modules\AI\Tools\ToolRegistry;
use Modules\Core\Support\WorkspaceContext;

final class AIServiceProvider extends ServiceProvider
{
    /**
     * The tools a model may call. Adding a capability means adding a class
     * here — there is no dynamic discovery, so the reachable surface is exactly
     * this list and can be reviewed as one.
     *
     * @var list<class-string<Tool>>
     */
    private const TOOLS = [
        GetSpendingSummary::class,
        GetTransactions::class,
        GetBudgetStatus::class,
        GetAccountBalances::class,
        GetUpcomingObligations::class,
        GetCashflowForecast::class,
        GetInvestmentPerformance::class,

        // Named `create_…` but incapable of creating anything: it returns a
        // proposal for the user to accept or throw away. See the class.
        CreateBudgetDraft::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/ai.php', 'ai');

        // With a key, the gateway; without one, rules. The deterministic
        // provider is not a mock — it is the supported no-key configuration,
        // which is what makes "the product works without AI" testable rather
        // than aspirational (docs/07-security.md §5.6).
        $this->app->bind(AiProvider::class, fn () => filled(config('ai.key'))
            ? $this->app->make(OpenAiProvider::class)
            : $this->app->make(DeterministicProvider::class));

        // Embeddings are bound separately from chat: a deployment may well
        // want a real embedding model behind its search while chat runs on
        // rules, or the other way round.
        $this->app->bind(EmbeddingProvider::class, fn () => config('ai.embeddings.driver') === 'http'
            ? $this->app->make(HttpEmbeddingProvider::class)
            : $this->app->make(LocalEmbeddingProvider::class));

        $this->app->singleton(AudioNormalizer::class);

        $this->app->singleton(ToolRegistry::class, fn () => new ToolRegistry(
            $this->app->make(WorkspaceContext::class),
            array_map(fn (string $tool) => $this->app->make($tool), self::TOOLS),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');

        $this->publishes([
            __DIR__.'/../Config/ai.php' => config_path('ai.php'),
        ], 'ai-config');

        // bootstrap/app.php maps only LedgerException; each later module
        // registers its own renderer so the wire format stays identical.
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if (! method_exists($handler, 'renderable')) {
                return;
            }

            $handler->renderable(fn (AiException $e, Request $request) => $request->expectsJson()
                ? $e->toResponse()
                : null);
        });
    }
}
