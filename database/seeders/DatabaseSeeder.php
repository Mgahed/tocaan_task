<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PaymentMethodEnum;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Demo Customer',
                'password' => Hash::make('password123'),
            ]
        );

        // Seed catalog products in database
        $product1 = Product::updateOrCreate(
            ['name' => 'Wireless Mechanical Keyboard'],
            [
                'description' => 'RGB Hot-swappable Wireless Mechanical Keyboard',
                'price' => 125.00,
                'stock' => 50,
                'is_active' => true,
            ]
        );

        $product2 = Product::updateOrCreate(
            ['name' => '27-inch 4K IPS Monitor'],
            [
                'description' => 'Ultra HD 144Hz IPS Display Monitor',
                'price' => 499.99,
                'stock' => 30,
                'is_active' => true,
            ]
        );

        $product3 = Product::updateOrCreate(
            ['name' => 'USB-C Docking Station'],
            [
                'description' => '12-in-1 Thunderbolt 4 USB-C Hub',
                'price' => 150.00,
                'stock' => 75,
                'is_active' => true,
            ]
        );

        $product4 = Product::updateOrCreate(
            ['name' => 'MacBook Pro 16-inch M4'],
            [
                'description' => 'Apple M4 Pro chip with 14-core CPU and 20-core GPU',
                'price' => 2499.00,
                'stock' => 20,
                'is_active' => true,
            ]
        );

        // 1. Pending order without payments (can be updated or deleted)
        $pendingOrder = Order::factory()->pending()->create([
            'user_id' => $user->id,
            'total_amount' => 250.00,
        ]);

        OrderItem::factory()->create([
            'order_id' => $pendingOrder->id,
            'product_id' => $product1->id,
            'product_name' => $product1->name,
            'quantity' => 2,
            'unit_price' => $product1->price,
            'subtotal' => 250.00,
        ]);

        // 2. Confirmed order ready for payment
        $confirmedOrder = Order::factory()->confirmed()->create([
            'user_id' => $user->id,
            'total_amount' => 499.99,
        ]);

        OrderItem::factory()->create([
            'order_id' => $confirmedOrder->id,
            'product_id' => $product2->id,
            'product_name' => $product2->name,
            'quantity' => 1,
            'unit_price' => $product2->price,
            'subtotal' => 499.99,
        ]);

        // 3. Confirmed order with a PENDING payment (ready for immediate Webhook testing in Postman)
        $webhookOrder = Order::factory()->confirmed()->create([
            'user_id' => $user->id,
            'total_amount' => 2499.00,
        ]);

        OrderItem::factory()->create([
            'order_id' => $webhookOrder->id,
            'product_id' => $product4->id,
            'product_name' => $product4->name,
            'quantity' => 1,
            'unit_price' => $product4->price,
            'subtotal' => 2499.00,
        ]);

        Payment::updateOrCreate(
            ['transaction_id' => 'cc_txn_pending_demo_001'],
            [
                'payment_reference' => '11111111-2222-3333-4444-555555555555',
                'order_id' => $webhookOrder->id,
                'payment_method' => PaymentMethodEnum::CREDIT_CARD->value,
                'status' => \App\Enums\PaymentStatusEnum::PENDING,
                'amount' => 2499.00,
                'gateway_response' => [
                    'gateway' => 'credit_card',
                    'status_reason' => '3ds_authentication_pending',
                ],
            ]
        );

        // 4. Confirmed order with a SUCCESSFUL payment (cannot be deleted)
        $paidOrder = Order::factory()->confirmed()->create([
            'user_id' => $user->id,
            'total_amount' => 150.00,
        ]);

        OrderItem::factory()->create([
            'order_id' => $paidOrder->id,
            'product_id' => $product3->id,
            'product_name' => $product3->name,
            'quantity' => 1,
            'unit_price' => $product3->price,
            'subtotal' => 150.00,
        ]);

        Payment::updateOrCreate(
            ['transaction_id' => 'cc_txn_paid_demo_002'],
            [
                'payment_reference' => '22222222-3333-4444-5555-666666666666',
                'order_id' => $paidOrder->id,
                'payment_method' => PaymentMethodEnum::CREDIT_CARD->value,
                'status' => \App\Enums\PaymentStatusEnum::SUCCESSFUL,
                'amount' => 150.00,
                'gateway_response' => [
                    'gateway' => 'credit_card',
                    'message' => 'Credit card charge approved.',
                ],
            ]
        );
    }
}
