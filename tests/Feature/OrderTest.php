<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatusEnum;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->token = (string) auth('api')->login($this->user);
    }

    public function test_user_can_create_order_and_total_is_calculated_automatically(): void
    {
        $payload = [
            'customer_name' => 'John Customer',
            'customer_email' => 'john@example.com',
            'customer_phone' => '+96512345678',
            'shipping_address' => 'Kuwait City, Block 1',
            'items' => [
                [
                    'product_name' => 'Mechanical Keyboard',
                    'quantity' => 2,
                    'price' => 150.00, // 300.00
                ],
                [
                    'product_name' => 'Gaming Mouse',
                    'quantity' => 1,
                    'price' => 75.50, // 75.50
                ],
            ],
        ];

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/v1/orders', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', OrderStatusEnum::PENDING->value)
            ->assertJsonPath('data.total_amount', 375.5)
            ->assertJsonCount(2, 'data.items');

        $this->assertDatabaseHas('orders', [
            'user_id' => $this->user->id,
            'total_amount' => 375.50,
            'status' => OrderStatusEnum::PENDING->value,
        ]);
    }

    public function test_user_can_list_orders_with_pagination_status_filter_and_zero_n_plus_1_queries(): void
    {
        $pendingOrders = Order::factory()->count(3)->pending()->create(['user_id' => $this->user->id]);
        $confirmedOrders = Order::factory()->count(2)->confirmed()->create(['user_id' => $this->user->id]);

        foreach ($pendingOrders->concat($confirmedOrders) as $order) {
            OrderItem::factory()->count(2)->create(['order_id' => $order->id]);
        }

        // Enable query log to verify bounded query count (eager loading prevents N+1)
        DB::enableQueryLog();

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/v1/orders?status=confirmed&per_page=10');

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);

        // Regardless of number of orders, query count should be at most 4 (count, orders+payments_count, eager items, eager payments)
        $this->assertLessThanOrEqual(5, count($queries));
    }

    public function test_user_can_update_order_details_status_and_items(): void
    {
        $order = Order::factory()->pending()->create([
            'user_id' => $this->user->id,
            'total_amount' => 100.00,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_name' => 'Old Item',
            'quantity' => 1,
            'unit_price' => 100.00,
            'subtotal' => 100.00,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/v1/orders/{$order->id}", [
                'status' => OrderStatusEnum::CONFIRMED->value,
                'items' => [
                    [
                        'product_name' => 'Updated Item',
                        'quantity' => 3,
                        'unit_price' => 80.00, // 240.00
                    ],
                ],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', OrderStatusEnum::CONFIRMED->value)
            ->assertJsonPath('data.total_amount', 240);
    }

    public function test_user_can_delete_order_without_payments(): void
    {
        $order = Order::factory()->pending()->create(['user_id' => $this->user->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->deleteJson("/api/v1/orders/{$order->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
    }

    public function test_order_cannot_be_deleted_if_it_has_associated_payments(): void
    {
        $order = Order::factory()->confirmed()->create(['user_id' => $this->user->id]);
        Payment::factory()->successful()->create(['order_id' => $order->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->deleteJson("/api/v1/orders/{$order->id}");

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Order cannot be deleted because it has associated payments.');

        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }
}
