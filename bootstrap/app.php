<?php

declare(strict_types=1);

use App\Exceptions\BusinessRuleException;
use App\Exceptions\UnsupportedPaymentGatewayException;
use App\Http\Middleware\EnsureIdempotency;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn (Request $request) => null);
        $middleware->alias([
            'idempotent' => EnsureIdempotency::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e): bool {
            return $request->is('api/*') || $request->expectsJson();
        });

        $exceptions->render(function (BusinessRuleException $e, Request $request): ?JsonResponse {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], $e->getStatusCode());
            }

            return null;
        });

        $exceptions->render(function (UnsupportedPaymentGatewayException $e, Request $request): ?JsonResponse {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], $e->getStatusCode());
            }

            return null;
        });

        $exceptions->render(function (ValidationException $e, Request $request): ?JsonResponse {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'errors' => $e->errors(),
                ], $e->status);
            }

            return null;
        });

        $exceptions->render(function (TokenExpiredException $e, Request $request): ?JsonResponse {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token has expired.',
                ], 401);
            }

            return null;
        });

        $exceptions->render(function (TokenInvalidException $e, Request $request): ?JsonResponse {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token is invalid.',
                ], 401);
            }

            return null;
        });

        $exceptions->render(function (JWTException $e, Request $request): ?JsonResponse {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authorization token not provided or invalid.',
                ], 401);
            }

            return null;
        });

        $exceptions->render(function (AuthenticationException $e, Request $request): ?JsonResponse {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            return null;
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request): ?JsonResponse {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'The requested resource was not found.',
                ], 404);
            }

            return null;
        });

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request): ?JsonResponse {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() ?: 'This action is unauthorized.',
                ], 403);
            }

            return null;
        });
    })->create();
