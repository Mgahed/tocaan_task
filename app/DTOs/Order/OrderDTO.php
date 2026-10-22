<?php

declare(strict_types=1);

namespace App\DTOs\Order;

use App\Enums\OrderStatusEnum;

readonly class OrderDTO
{
    /**
     * @param array<int, array{product_id: int, quantity: int}> $requestedItems
     */
    public function __construct(
        public int $userId,
        public ?string $shippingAddress,
        public ?string $notes,
        public OrderStatusEnum $status,
        public array $requestedItems,
    ) {}

    /**
     * @param array<string, mixed> $validated
     */
    public static function fromRequest(int $userId, array $validated): self
    {
        $requestedItems = array_map(
            static fn (array $item): array => [
                'product_id' => (int) $item['product_id'],
                'quantity' => (int) $item['quantity'],
            ],
            $validated['items'] ?? []
        );

        $status = isset($validated['status'])
            ? OrderStatusEnum::from((string) $validated['status'])
            : OrderStatusEnum::PENDING;

        return new self(
            userId: $userId,
            shippingAddress: isset($validated['shipping_address']) ? (string) $validated['shipping_address'] : null,
            notes: isset($validated['notes']) ? (string) $validated['notes'] : null,
            status: $status,
            requestedItems: $requestedItems,
        );
    }

    /**
     * Calculate the total amount across resolved OrderItemDTOs.
     *
     * @param array<int, OrderItemDTO> $resolvedItems
     */
    public static function calculateTotalFromItems(array $resolvedItems): float
    {
        $total = array_reduce(
            $resolvedItems,
            static fn (float $carry, OrderItemDTO $item): float => $carry + $item->subtotal,
            0.0
        );

        return round($total, 2);
    }
}
