<?php

declare(strict_types=1);

namespace Modules\Business\Providers;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Modules\Business\Exceptions\BusinessException;

final class BusinessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/business.php', 'business');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');

        $this->publishes([
            __DIR__.'/../Config/business.php' => config_path('business.php'),
        ], 'business-config');

        $this->registerExceptionRenderer();
    }

    /**
     * A business refusal answers with its own machine-readable code rather than
     * a bare 500. Registered here rather than in bootstrap/app.php so the
     * module stays self-contained: adding it is one line in the provider list.
     */
    private function registerExceptionRenderer(): void
    {
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if (! method_exists($handler, 'renderable')) {
                return;
            }

            $handler->renderable(
                fn (BusinessException $e, Request $request) => $request->expectsJson() ? $e->toResponse() : null,
            );
        });
    }
}
