<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->redirectGuestsTo(fn () => null);
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
            'permission' => \App\Http\Middleware\RequirePermission::class,
            'customer.session' => \App\Http\Middleware\AuthenticateCustomerSession::class,
        ]);
    })

    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(fn ($request, $throwable) => $request->is('api/*') || $request->expectsJson());
    })->create();
