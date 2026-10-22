<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PaymentMethodEnum;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
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

        // 1. Pending order without payments (can be updated or deleted)
        $pendingOrder = Order::factory()->pending()->create([
            'user_id' => $user->id,
            'customer_name' => $user->name,
            'customer_email' => $user->email,
            'total_amount' => 250.00,
        ]);

        OrderItem::factory()->create([
            'order_id' => $pendingOrder->id,
            'product_name' => 'Wireless Mechanical Keyboard',
            'quantity' => 2,
            'unit_price' => 125.00,
            'subtotal' => 250.00,
        ]);

        // 2. Confirmed order ready for payment
        $confirmedOrder = Order::factory()->confirmed()->create([
            'user_id' => $user->id,
            'customer_name' => $user->name,
            'customer_email' => $user->email,
            'total_amount' => 499.99,
        ]);

        OrderItem::factory()->create([
            'order_id' => $confirmedOrder->id,
            'product_name' => '27-inch 4K IPS Monitor',
            'quantity' => 1,
            'unit_price' => 499.99,
            'subtotal' => 499.99,
        ]);

        // 3. Confirmed order with a successful payment (cannot be deleted)
        $paidOrder = Order::factory()->confirmed()->create([
            'user_id' => $user->id,
            'customer_name' => $user->name,
            'customer_email' => $user->email,
            'total_amount' => 150.00,
        ]);

        OrderItem::factory()->create([
            'order_id' => $paidOrder->id,
            'product_name' => 'USB-C Docking Station',
            'quantity' => 1,
            'unit_price' => 150.00,
            'subtotal' => 150.00,
        ]);

        Payment::factory()->successful()->create([
            'order_id' => $paidOrder->id,
            'payment_method' => PaymentMethodEnum::CREDIT_CARD->value,
            'amount' => 150.00,
        ]);
    }
}
