<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Modules\Ledger\Exceptions\LedgerException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Domain refusals answer with a stable machine-readable code, so the
        // client can translate the message itself (docs/05-api-conventions.md).
        $exceptions->render(function (LedgerException $e, Request $request) {
            return $request->expectsJson() ? $e->toResponse() : null;
        });
    })->create();
