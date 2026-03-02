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
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->validateCsrfTokens(except: ['api/webhook/payment', 'api/webhooks/midtrans']);

        $middleware->redirectGuestsTo(fn () => env('FRONTEND_URL', 'http://localhost:3000') . '/login');
        $middleware->redirectUsersTo(fn () => env('FRONTEND_URL', 'http://localhost:3000') . '/');

        $middleware->alias([
            'affiliate.active' => \App\Http\Middleware\EnsureAffiliateActive::class,
            'role'             => \App\Http\Middleware\EnsureUserRole::class,
            'track.affiliate'  => \App\Http\Middleware\TrackAffiliateClick::class,
        ]);

        $middleware->append(\App\Http\Middleware\NoCacheMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
