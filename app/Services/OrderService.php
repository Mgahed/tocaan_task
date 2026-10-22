<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\Order\OrderDTO;
use App\DTOs\Order\OrderItemDTO;
use App\Enums\OrderStatusEnum;
use App\Exceptions\BusinessRuleException;
use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class OrderService
{
    private const CACHE_TTL_SECONDS = 300;

    /**
     * Retrieve paginated orders for the authenticated user with optional status filtering.
     * Eager-loads items and payments to strictly prevent N+1 query issues.
     */
    public function listUserOrders(
        int $userId,
        ?string $status = null,
        int $perPage = 15,
        int $page = 1
    ): LengthAwarePaginator {
        $version = $this->getUserCacheVersion($userId);
        $statusKey = $status ?? 'all';
        $cacheKey = "orders:user:{$userId}:v{$version}:status:{$statusKey}:per_page:{$perPage}:page:{$page}";

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($userId, $status, $perPage, $page): LengthAwarePaginator {
            return Order::query()
                ->where('user_id', $userId)
                ->with(['items', 'payments'])
                ->withCount('payments')
                ->status($status)
                ->latest('id')
                ->paginate($perPage, ['*'], 'page', $page);
        });
    }

    /**
     * Retrieve a single order with eager-loaded relations and verify ownership.
     *
     * @throws BusinessRuleException
     */
    public function getOrderForUser(int $orderId, int $userId): Order
    {
        $cacheKey = "orders:detail:{$orderId}";

        /** @var Order|null $order */
        $order = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($orderId): ?Order {
            return Order::query()
                ->with(['items', 'payments', 'user'])
                ->withCount('payments')
                ->find($orderId);
        });

        if ($order === null) {
            throw new BusinessRuleException('Order not found.', 404);
        }

        if ($order->user_id !== $userId) {
            throw new BusinessRuleException('You are not authorized to access this order.', 403);
        }

        return $order;
    }

    /**
     * Create a new order along with its line items inside a database transaction.
     */
    public function createOrder(OrderDTO $dto): Order
    {
        $order = DB::transaction(function () use ($dto): Order {
            $totalAmount = $dto->calculateTotal();

            $order = Order::create([
                'user_id' => $dto->userId,
                'status' => $dto->status,
                'total_amount' => $totalAmount,
                'customer_name' => $dto->customerName,
                'customer_email' => $dto->customerEmail,
                'customer_phone' => $dto->customerPhone,
                'shipping_address' => $dto->shippingAddress,
                'notes' => $dto->notes,
            ]);

            $itemsPayload = array_map(
                static fn (OrderItemDTO $item): array => $item->toArray(),
                $dto->items
            );

            $order->items()->createMany($itemsPayload);

            return $order->load(['items', 'payments'])->loadCount('payments');
        });

        $this->invalidateOrderCache($dto->userId, $order->id);

        return $order;
    }

    /**
     * Update an existing order with pessimistic database locking (lockForUpdate).
     *
     * @param array<string, mixed> $validated
     * @throws BusinessRuleException
     */
    public function updateOrder(int $orderId, int $userId, array $validated): Order
    {
        $order = DB::transaction(function () use ($orderId, $userId, $validated): Order {
            /** @var Order|null $lockedOrder */
            $lockedOrder = Order::query()
                ->where('id', $orderId)
                ->lockForUpdate()
                ->first();

            if ($lockedOrder === null) {
                throw new BusinessRuleException('Order not found.', 404);
            }

            if ($lockedOrder->user_id !== $userId) {
                throw new BusinessRuleException('You are not authorized to update this order.', 403);
            }

            $hasSuccessfulPayment = $lockedOrder->hasSuccessfulPayment();

            if ($hasSuccessfulPayment && (isset($validated['items']) || isset($validated['status']))) {
                throw new BusinessRuleException(
                    'Cannot modify order items or status for an order that has already been paid.',
                    422
                );
            }

            // Update scalar order attributes if provided
            $updatableFields = ['customer_name', 'customer_email', 'customer_phone', 'shipping_address', 'notes'];
            foreach ($updatableFields as $field) {
                if (array_key_exists($field, $validated)) {
                    $lockedOrder->{$field} = $validated[$field];
                }
            }

            if (isset($validated['status'])) {
                $lockedOrder->status = OrderStatusEnum::from((string) $validated['status']);
            }

            // If items are provided, recalculate subtotals and total amount
            if (isset($validated['items']) && is_array($validated['items'])) {
                if ($lockedOrder->payments()->exists()) {
                    throw new BusinessRuleException(
                        'Cannot modify order items once payment attempts have been recorded for this order.',
                        422
                    );
                }

                $itemDTOs = array_map(
                    static fn (array $item): OrderItemDTO => OrderItemDTO::fromArray($item),
                    $validated['items']
                );

                $newTotal = array_reduce(
                    $itemDTOs,
                    static fn (float $carry, OrderItemDTO $item): float => $carry + $item->subtotal,
                    0.0
                );

                $lockedOrder->items()->delete();
                $lockedOrder->items()->createMany(
                    array_map(static fn (OrderItemDTO $item): array => $item->toArray(), $itemDTOs)
                );

                $lockedOrder->total_amount = round($newTotal, 2);
            }

            $lockedOrder->save();

            return $lockedOrder->load(['items', 'payments'])->loadCount('payments');
        });

        $this->invalidateOrderCache($userId, $order->id);

        return $order;
    }

    /**
     * Delete an order only if no payments are associated, using pessimistic locking.
     *
     * @throws BusinessRuleException
     */
    public function deleteOrder(int $orderId, int $userId): void
    {
        DB::transaction(function () use ($orderId, $userId): void {
            /** @var Order|null $lockedOrder */
            $lockedOrder = Order::query()
                ->where('id', $orderId)
                ->lockForUpdate()
                ->first();

            if ($lockedOrder === null) {
                throw new BusinessRuleException('Order not found.', 404);
            }

            if ($lockedOrder->user_id !== $userId) {
                throw new BusinessRuleException('You are not authorized to delete this order.', 403);
            }

            // Business Rule: Orders cannot be deleted if they have associated payments
            if ($lockedOrder->payments()->exists()) {
                throw new BusinessRuleException(
                    'Order cannot be deleted because it has associated payments.',
                    422
                );
            }

            $lockedOrder->items()->delete();
            $lockedOrder->delete();
        });

        $this->invalidateOrderCache($userId, $orderId);
    }

    /**
     * Invalidate cached order listings and detail entry for a user.
     */
    public function invalidateOrderCache(int $userId, ?int $orderId = null): void
    {
        $versionKey = "orders:user:{$userId}:version";
        $currentVersion = (int) Cache::get($versionKey, 1);
        Cache::forever($versionKey, $currentVersion + 1);

        if ($orderId !== null) {
            Cache::forget("orders:detail:{$orderId}");
        }
    }

    /**
     * Get the current cache version number for a user's order list.
     */
    private function getUserCacheVersion(int $userId): int
    {
        return (int) Cache::rememberForever("orders:user:{$userId}:version", static fn (): int => 1);
    }
}
