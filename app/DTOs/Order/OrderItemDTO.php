<?php

declare(strict_types=1);

namespace App\DTOs\Order;

use App\Models\Product;

readonly class OrderItemDTO
{
    public float $subtotal;

    public function __construct(
        public int $productId,
        public string $productName,
        public int $quantity,
        public float $unitPrice,
    ) {
        $this->subtotal = round($this->quantity * $this->unitPrice, 2);
    }

    /**
     * Create an OrderItemDTO from a database Product model and requested quantity.
     */
    public static function fromProduct(Product $product, int $quantity): self
    {
        return new self(
            productId: $product->id,
            productName: $product->name,
            quantity: $quantity,
            unitPrice: round((float) $product->price, 2),
        );
    }

    /**
     * @return array{product_id: int, product_name: string, quantity: int, unit_price: float, subtotal: float}
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'product_name' => $this->productName,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
            'subtotal' => $this->subtotal,
        ];
    }
}
