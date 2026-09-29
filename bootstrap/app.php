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
            $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

            $middleware->alias([
                'role' => \App\Http\Middleware\EnsureRole::class,
            ]);

            $middleware->redirectGuestsTo(fn () => null);
        })
->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->shouldRenderJsonWhen(
        fn (Request $request, Throwable $e) =>
            $request->expectsJson()
            || $request->is('api/*')
            || $request->is('api')
            || str_starts_with($request->getPathInfo(), '/api')
            || !empty($request->header('Authorization'))
    );

    $exceptions->render(function (AuthenticationException $e, Request $request) {
        return response()->json([
            'message' => 'Unauthenticated.',
        ], 401);
    });

    $exceptions->render(function (\Illuminate\Http\Exceptions\HttpResponseException $e, Request $request) {
        $response = $e->getResponse();

        if ($response instanceof \Illuminate\Http\RedirectResponse) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        return $response;
    });

    $exceptions->render(function (\Illuminate\Validation\ValidationException $e, Request $request) {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        }

        return null;
    });

    $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e, Request $request) {
        if ($request->expectsJson() || $request->is('api/*')) {
            $statusCode = $e->getStatusCode();

            return response()->json([
                'message' => $statusCode === 500
                    ? 'Terjadi kesalahan internal pada server.'
                    : ($e->getMessage() ?: 'Terjadi kesalahan.'),
            ], $statusCode);
        }

        return null;
    });

    $exceptions->render(function (Throwable $exception, $request) {
        if ($request->expectsJson() || $request->is('api/*')) {
            $statusCode = method_exists($exception, 'getStatusCode')
                ? $exception->getStatusCode()
                : 500;

            if ($statusCode === 422) {
                return null;
            }

            return response()->json([
                'message' => ($statusCode === 500
                    ? 'Terjadi kesalahan internal pada server.'
                    : ($exception->getMessage() ?: 'Terjadi kesalahan.')),
                'error' => config('app.debug') ? $exception->getMessage() : null
            ], $statusCode);
        }

        return null;
    });
})->create();