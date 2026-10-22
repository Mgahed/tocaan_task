<?php

declare(strict_types=1);

namespace App\DTOs\Order;

readonly class OrderItemDTO
{
    public float $subtotal;

    public function __construct(
        public string $productName,
        public int $quantity,
        public float $unitPrice,
    ) {
        $this->subtotal = round($this->quantity * $this->unitPrice, 2);
    }

    /**
     * @param array{product_name: string, quantity: int|string, unit_price: float|int|string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            productName: trim($data['product_name']),
            quantity: (int) $data['quantity'],
            unitPrice: round((float) $data['unit_price'], 2),
        );
    }

    /**
     * @return array{product_name: string, quantity: int, unit_price: float, subtotal: float}
     */
    public function toArray(): array
    {
        return [
            'product_name' => $this->productName,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
            'subtotal' => $this->subtotal,
        ];
    }
}
