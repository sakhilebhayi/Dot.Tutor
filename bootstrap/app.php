<?php

use App\Domain\Integration\Support\JsonErrors;
use App\Http\Middleware\EnsurePlatformOperator;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'operator' => EnsurePlatformOperator::class,
        ]);

        // Global, so the health check, error pages, Livewire requests and /csp-report carry the headers too.
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // One policy (R-47 D-1404): JSON with {error, message} whenever the request is under api/* or expects JSON.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => JsonErrors::wantsJson($request));
        $exceptions->render(fn (Throwable $e, Request $request) => JsonErrors::render($e, $request));
        $exceptions->respond(fn ($response, Throwable $e, Request $request) => JsonErrors::finish($response, $request));
    })->create();
