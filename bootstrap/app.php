<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // ✅ SECURITY HEADERS (GLOBAL)
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Tangani AuthenticationException secara khusus agar mengembalikan status 401
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api*') || !empty($request->header('Authorization'))) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                ], 401);
            }
        });

        $exceptions->render(function (Throwable $exception, $request) {
            if ($request->expectsJson() || $request->is('api*')) {
                $statusCode = method_exists($exception, 'getStatusCode') 
                    ? $exception->getStatusCode() 
                    : 500;

                if ($statusCode === 422) {
                    return null; 
                }

                return response()->json([
                    'message' => ($statusCode === 500 
                        ? 'Terjadi kesalahan internal pada server.' 
                        : ($exception->getMessage() ?: 'Terjadi kesaluran.')),
                    'error' => app()->environment('local') ? $exception->getMessage() : null
                ], $statusCode);
            }

            return null;
        });
    })->create();