<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\Order\OrderDTO;
use App\DTOs\Order\OrderItemDTO;
use App\Enums\OrderStatusEnum;
use App\Exceptions\BusinessRuleException;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class OrderService
{
    private const CACHE_TTL_SECONDS = 300;

    /**
     * Retrieve paginated orders for the authenticated user with optional status filtering.
     * Eager-loads user, items, and payments to strictly prevent N+1 query issues.
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
                ->with(['user', 'items', 'payments'])
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
                ->with(['user', 'items', 'payments'])
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
     * Create a new order for the authenticated user by resolving product names and prices from the database.
     *
     * @throws BusinessRuleException
     */
    public function createOrder(OrderDTO $dto): Order
    {
        $order = DB::transaction(function () use ($dto): Order {
            $resolvedItems = $this->resolveOrderItemsFromDatabase($dto->requestedItems);
            $totalAmount = OrderDTO::calculateTotalFromItems($resolvedItems);

            $order = Order::create([
                'user_id' => $dto->userId,
                'status' => $dto->status,
                'total_amount' => $totalAmount,
                'shipping_address' => $dto->shippingAddress,
                'notes' => $dto->notes,
            ]);

            $itemsPayload = array_map(
                static fn (OrderItemDTO $item): array => $item->toArray(),
                $resolvedItems
            );

            $order->items()->createMany($itemsPayload);

            return $order->load(['user', 'items', 'payments'])->loadCount('payments');
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
            $updatableFields = ['shipping_address', 'notes'];
            foreach ($updatableFields as $field) {
                if (array_key_exists($field, $validated)) {
                    $lockedOrder->{$field} = $validated[$field];
                }
            }

            if (isset($validated['status'])) {
                $lockedOrder->status = OrderStatusEnum::from((string) $validated['status']);
            }

            // If items are provided, resolve products from DB and recalculate subtotals & total amount
            if (isset($validated['items']) && is_array($validated['items'])) {
                if ($lockedOrder->payments()->exists()) {
                    throw new BusinessRuleException(
                        'Cannot modify order items once payment attempts have been recorded for this order.',
                        422
                    );
                }

                $requestedItems = array_map(
                    static fn (array $item): array => [
                        'product_id' => (int) $item['product_id'],
                        'quantity' => (int) $item['quantity'],
                    ],
                    $validated['items']
                );

                $resolvedItems = $this->resolveOrderItemsFromDatabase($requestedItems);
                $newTotal = OrderDTO::calculateTotalFromItems($resolvedItems);

                $lockedOrder->items()->delete();
                $lockedOrder->items()->createMany(
                    array_map(static fn (OrderItemDTO $item): array => $item->toArray(), $resolvedItems)
                );

                $lockedOrder->total_amount = $newTotal;
            }

            $lockedOrder->save();

            return $lockedOrder->load(['user', 'items', 'payments'])->loadCount('payments');
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
     * Resolve requested product IDs and quantities in a single batch query from the database (zero N+1).
     *
     * @param array<int, array{product_id: int, quantity: int}> $requestedItems
     * @return array<int, OrderItemDTO>
     * @throws BusinessRuleException
     */
    private function resolveOrderItemsFromDatabase(array $requestedItems): array
    {
        $productIds = array_values(array_unique(array_column($requestedItems, 'product_id')));

        // Fetch all products in one query (preventing N+1)
        $products = Product::query()
            ->whereIn('id', $productIds)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $resolvedItems = [];
        foreach ($requestedItems as $item) {
            /** @var Product|null $product */
            $product = $products->get($item['product_id']);

            if ($product === null) {
                throw new BusinessRuleException(
                    "Product with ID [{$item['product_id']}] is unavailable or inactive.",
                    422
                );
            }

            $resolvedItems[] = OrderItemDTO::fromProduct($product, $item['quantity']);
        }

        return $resolvedItems;
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
