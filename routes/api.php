<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // Public Authentication Routes
    Route::prefix('auth')->group(function (): void {
        Route::post('register', [AuthController::class, 'register'])->name('api.v1.auth.register');
        Route::post('login', [AuthController::class, 'login'])->name('api.v1.auth.login');

        // Protected Auth Routes
        Route::middleware('auth:api')->group(function (): void {
            Route::get('me', [AuthController::class, 'me'])->name('api.v1.auth.me');
            Route::post('refresh', [AuthController::class, 'refresh'])->name('api.v1.auth.refresh');
            Route::post('logout', [AuthController::class, 'logout'])->name('api.v1.auth.logout');
        });
    });

    // Public Webhook Endpoint (Protected via HMAC Signature Verification)
    Route::post('webhooks/payments/{gateway}', [PaymentWebhookController::class, 'handle'])
        ->name('api.v1.webhooks.payments.handle');

    // Protected Order & Payment Management Routes
    Route::middleware('auth:api')->group(function (): void {
        // Orders
        Route::get('orders', [OrderController::class, 'index'])->name('api.v1.orders.index');
        Route::post('orders', [OrderController::class, 'store'])
            ->middleware('idempotent')
            ->name('api.v1.orders.store');
        Route::get('orders/{order}', [OrderController::class, 'show'])
            ->whereNumber('order')
            ->name('api.v1.orders.show');
        Route::put('orders/{order}', [OrderController::class, 'update'])
            ->whereNumber('order')
            ->name('api.v1.orders.update');
        Route::delete('orders/{order}', [OrderController::class, 'destroy'])
            ->whereNumber('order')
            ->name('api.v1.orders.destroy');

        // Payments
        Route::get('payments', [PaymentController::class, 'index'])->name('api.v1.payments.index');
        Route::get('orders/{order}/payments', [PaymentController::class, 'orderPayments'])
            ->whereNumber('order')
            ->name('api.v1.orders.payments.index');
        Route::post('orders/{order}/payments', [PaymentController::class, 'store'])
            ->whereNumber('order')
            ->middleware('idempotent')
            ->name('api.v1.orders.payments.store');
    });
});
