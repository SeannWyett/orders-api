<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\OrderNotCancellableException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Illuminate\Auth\AuthenticationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['idempotent' => \App\Http\Middleware\EnsureIdempotency::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {

        // Always JSON for the API, even without an Accept header
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson()
        );

        // 422: insufficient stock (same shape as Laravel's validation 422)
        $exceptions->render(function (InsufficientStockException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors'  => ['items' => [$e->getMessage()]],
            ], 422);
        });

        // 409: cancelling a non-pending order
        $exceptions->render(function (OrderNotCancellableException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => (object) [],
            ], 409);
        });

        // 401: Unauthenticated Users accessing protected routes
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                    'errors' => (object) [],
                ], 401);

            }
        });

        // 404: missing order_number AND unknown routes
        // (ModelNotFoundException is converted to this before callbacks run)
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Resource not found.',
                    'errors' => (object) [],
                ], 404);
            }
        });

        // 500: catch-all. Must be registered LAST, and must skip exceptions
        // that already have their own status code or response.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')
                || config('app.debug')
                || $e instanceof HttpException
                || $e instanceof ValidationException) {
                return null; // fall through to Laravel's default handling
            }

            return response()->json([
                'message' => 'Server error. Please try again later.',
                'errors' => (object)[]
            ], 500);
        });
    })->create();
