<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTOs\Order\OrderDTO;
use App\DTOs\Order\OrderItemDTO;
use App\Enums\OrderStatusEnum;
use PHPUnit\Framework\TestCase;

class OrderCalculationTest extends TestCase
{
    public function test_it_accurately_calculates_item_subtotals_and_order_total(): void
    {
        $dto = OrderDTO::fromRequest(1, [
            'customer_name' => 'John Doe',
            'customer_email' => 'john@example.com',
            'status' => OrderStatusEnum::PENDING->value,
            'items' => [
                [
                    'product_name' => 'Laptop Stand',
                    'quantity' => 3,
                    'unit_price' => 45.50, // 136.50
                ],
                [
                    'product_name' => 'USB-C Cable',
                    'quantity' => 2,
                    'unit_price' => 12.99, // 25.98
                ],
            ],
        ]);

        $this->assertCount(2, $dto->items);
        $this->assertInstanceOf(OrderItemDTO::class, $dto->items[0]);
        $this->assertSame(136.50, $dto->items[0]->subtotal);
        $this->assertSame(25.98, $dto->items[1]->subtotal);
        $this->assertSame(162.48, $dto->calculateTotal());
    }
}
