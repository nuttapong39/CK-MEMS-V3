<?php

use App\Http\Middleware\RoleGate;
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
        $middleware->alias([
            'role' => RoleGate::class,
        ]);

        // This is a JWT / API-only app — there is no 'login' named route.
        // Returning null tells Authenticate middleware not to redirect guests;
        // the AuthenticationException is then caught by withExceptions below.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API routes must always return JSON — never redirect to route('login') which doesn't exist
        $exceptions->renderable(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated. กรุณาเข้าสู่ระบบใหม่'], 401);
            }
        });
    })->create();
