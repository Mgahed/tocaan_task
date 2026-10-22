<?php

declare(strict_types=1);

namespace App\DTOs\Order;

use App\Enums\OrderStatusEnum;

readonly class OrderDTO
{
    /**
     * @param array<int, OrderItemDTO> $items
     */
    public function __construct(
        public int $userId,
        public string $customerName,
        public string $customerEmail,
        public ?string $customerPhone,
        public ?string $shippingAddress,
        public ?string $notes,
        public OrderStatusEnum $status,
        public array $items,
    ) {}

    /**
     * @param array<string, mixed> $validated
     */
    public static function fromRequest(int $userId, array $validated): self
    {
        $items = array_map(
            static fn (array $item): OrderItemDTO => OrderItemDTO::fromArray($item),
            $validated['items'] ?? []
        );

        $status = isset($validated['status'])
            ? OrderStatusEnum::from((string) $validated['status'])
            : OrderStatusEnum::PENDING;

        return new self(
            userId: $userId,
            customerName: (string) $validated['customer_name'],
            customerEmail: (string) $validated['customer_email'],
            customerPhone: isset($validated['customer_phone']) ? (string) $validated['customer_phone'] : null,
            shippingAddress: isset($validated['shipping_address']) ? (string) $validated['shipping_address'] : null,
            notes: isset($validated['notes']) ? (string) $validated['notes'] : null,
            status: $status,
            items: $items,
        );
    }

    /**
     * Calculate the total amount across all order items.
     */
    public function calculateTotal(): float
    {
        $total = array_reduce(
            $this->items,
            static fn (float $carry, OrderItemDTO $item): float => $carry + $item->subtotal,
            0.0
        );

        return round($total, 2);
    }
}
