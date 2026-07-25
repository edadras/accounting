<?php

declare(strict_types=1);

namespace Modules\Investment\Providers;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Modules\Investment\Exceptions\InvestmentException;

final class InvestmentServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');

        // The module registers its own error renderer rather than adding a line
        // to bootstrap/app.php, so installing it stays a one-line change.
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if (! method_exists($handler, 'renderable')) {
                return;
            }

            $handler->renderable(fn (InvestmentException $e, Request $request) => $request->expectsJson()
                ? $e->toResponse()
                : null);
        });
    }
}
