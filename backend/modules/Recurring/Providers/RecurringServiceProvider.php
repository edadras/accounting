<?php

declare(strict_types=1);

namespace Modules\Recurring\Providers;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Modules\Recurring\Console\PostDueRecurringCommand;
use Modules\Recurring\Exceptions\RecurringException;

final class RecurringServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([PostDueRecurringCommand::class]);
        }

        $this->registerExceptionRenderer();
    }

    /**
     * A recurring refusal answers with its own machine-readable code rather than
     * a bare 500. Registered here rather than in bootstrap/app.php so the module
     * stays self-contained: adding it is one line in the provider list.
     */
    private function registerExceptionRenderer(): void
    {
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if (! method_exists($handler, 'renderable')) {
                return;
            }

            $handler->renderable(
                fn (RecurringException $e, Request $request) => $request->expectsJson() ? $e->toResponse() : null,
            );
        });
    }
}
