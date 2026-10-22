<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTOs\Order\OrderDTO;
use App\DTOs\Order\OrderItemDTO;
use App\Models\Product;
use PHPUnit\Framework\TestCase;

class OrderCalculationTest extends TestCase
{
    public function test_it_accurately_calculates_item_subtotals_and_order_total_from_products(): void
    {
        $product1 = new Product([
            'name' => 'Laptop Stand',
            'price' => 45.50,
        ]);
        $product1->id = 1;

        $product2 = new Product([
            'name' => 'USB-C Cable',
            'price' => 12.99,
        ]);
        $product2->id = 2;

        $item1 = OrderItemDTO::fromProduct($product1, 3); // 136.50
        $item2 = OrderItemDTO::fromProduct($product2, 2); // 25.98

        $this->assertSame(136.50, $item1->subtotal);
        $this->assertSame(25.98, $item2->subtotal);
        $this->assertSame(162.48, OrderDTO::calculateTotalFromItems([$item1, $item2]));
    }
}
