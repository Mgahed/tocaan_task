<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\Payment\PaymentPayloadDTO;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\UnsupportedPaymentGatewayException;
use App\Jobs\PaymentProcessedJob;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\PaymentGatewayFactory;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function __construct(
        private readonly PaymentGatewayFactory $gatewayFactory,
        private readonly OrderService $orderService,
    ) {}

    /**
     * Retrieve all payments belonging to the authenticated user's orders (paginated).
     * Uses eager loading to prevent N+1 queries.
     */
    public function listUserPayments(
        int $userId,
        ?string $status = null,
        ?string $method = null,
        int $perPage = 15,
        int $page = 1
    ): LengthAwarePaginator {
        return Payment::query()
            ->whereHas('order', static function ($query) use ($userId): void {
                $query->where('user_id', $userId);
            })
            ->with(['order', 'order.items'])
            ->status($status)
            ->method($method)
            ->latest('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Retrieve paginated payments for a specific order belonging to the user.
     *
     * @throws BusinessRuleException
     */
    public function listOrderPayments(
        int $orderId,
        int $userId,
        int $perPage = 15,
        int $page = 1
    ): LengthAwarePaginator {
        $order = Order::query()->find($orderId);

        if ($order === null) {
            throw new BusinessRuleException('Order not found.', 404);
        }

        if ($order->user_id !== $userId) {
            throw new BusinessRuleException('You are not authorized to view payments for this order.', 403);
        }

        return Payment::query()
            ->where('order_id', $orderId)
            ->with(['order'])
            ->latest('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Process a payment for a confirmed order using the appropriate PaymentGatewayInterface strategy.
     * Protected by both a distributed Redis atomic lock and a database row lock (lockForUpdate).
     *
     * @throws BusinessRuleException
     * @throws UnsupportedPaymentGatewayException
     */
    public function processPayment(int $orderId, int $userId, PaymentPayloadDTO $payload): Payment
    {
        // 1. Resolve the payment gateway strategy via Factory (throws UnsupportedPaymentGatewayException if invalid)
        $gateway = $this->gatewayFactory->make($payload->paymentMethod);

        // 2. Acquire a distributed Redis lock per order to prevent concurrent double-charge requests
        $lockSeconds = (int) config('payment.lock_timeout_seconds', 15);
        $lock = Cache::lock("order_payment:{$orderId}", $lockSeconds);

        try {
            $lock->block(5);

            $payment = DB::transaction(function () use ($orderId, $userId, $payload, $gateway): Payment {
                // 3. Acquire pessimistic row-level lock on the Order inside the transaction
                /** @var Order|null $lockedOrder */
                $lockedOrder = Order::query()
                    ->where('id', $orderId)
                    ->lockForUpdate()
                    ->first();

                if ($lockedOrder === null) {
                    throw new BusinessRuleException('Order not found.', 404);
                }

                if ($lockedOrder->user_id !== $userId) {
                    throw new BusinessRuleException('You are not authorized to pay for this order.', 403);
                }

                // 4. Business Rule: Payments can only be processed for orders in the confirmed status
                if (! $lockedOrder->isConfirmed()) {
                    throw new BusinessRuleException(
                        "Payments can only be processed for orders in the 'confirmed' status. Current order status is '{$lockedOrder->status->value}'.",
                        422
                    );
                }

                // 5. Prevent duplicate charges if the order already has a successful payment
                if ($lockedOrder->hasSuccessfulPayment()) {
                    throw new BusinessRuleException(
                        'This order has already been paid successfully.',
                        422
                    );
                }

                // 6. Check if a payment with the same idempotency_key already exists
                if ($payload->idempotencyKey !== null) {
                    $existingPayment = Payment::query()
                        ->where('idempotency_key', $payload->idempotencyKey)
                        ->with('order')
                        ->first();

                    if ($existingPayment !== null) {
                        return $existingPayment;
                    }
                }

                // 7. Execute the selected PaymentGatewayInterface strategy
                $result = $gateway->charge($lockedOrder, $payload);

                // 8. Persist the payment record
                $payment = Payment::create([
                    'order_id' => $lockedOrder->id,
                    'payment_method' => $gateway->getIdentifier(),
                    'status' => $result->status,
                    'amount' => $lockedOrder->total_amount,
                    'transaction_id' => $result->transactionId,
                    'gateway_response' => $result->gatewayResponse,
                    'idempotency_key' => $payload->idempotencyKey,
                ]);

                return $payment->load('order');
            });
        } catch (LockTimeoutException) {
            throw new BusinessRuleException(
                'Another payment is currently being processed for this order. Please try again momentarily.',
                409
            );
        } finally {
            $lock->release();
        }

        // 9. Invalidate cached order listings/details
        $this->orderService->invalidateOrderCache($userId, $orderId);

        // 10. Dispatch background job to RabbitMQ (`payments` queue)
        PaymentProcessedJob::dispatch($payment->id)->onQueue('payments')->afterCommit();

        return $payment;
    }
}
