<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatusEnum;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
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

        $this->user = User::factory()->create([
            'name' => 'Authenticated Customer',
            'email' => 'customer@example.com',
        ]);
        $this->token = (string) auth('api')->login($this->user);
    }

    public function test_user_can_create_order_using_product_ids_and_authenticated_user_token(): void
    {
        $keyboard = Product::factory()->create([
            'name' => 'Mechanical Keyboard',
            'price' => 150.00,
        ]);

        $mouse = Product::factory()->create([
            'name' => 'Gaming Mouse',
            'price' => 75.50,
        ]);

        $payload = [
            'shipping_address' => 'Kuwait City, Block 1',
            'notes' => 'Leave at reception',
            'items' => [
                [
                    'product_id' => $keyboard->id,
                    'quantity' => 2, // 2 * 150.00 = 300.00
                ],
                [
                    'product_id' => $mouse->id,
                    'quantity' => 1, // 1 * 75.50 = 75.50
                ],
            ],
        ];

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/v1/orders', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user_id', $this->user->id)
            ->assertJsonPath('data.user.email', 'customer@example.com')
            ->assertJsonPath('data.status', OrderStatusEnum::PENDING->value)
            ->assertJsonPath('data.total_amount', 375.5)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.product_id', $keyboard->id)
            ->assertJsonPath('data.items.0.product_name', 'Mechanical Keyboard')
            ->assertJsonPath('data.items.0.unit_price', 150);

        $this->assertDatabaseHas('orders', [
            'user_id' => $this->user->id,
            'total_amount' => 375.50,
            'status' => OrderStatusEnum::PENDING->value,
        ]);
    }

    public function test_user_can_list_orders_with_pagination_status_filter_and_zero_n_plus_1_queries(): void
    {
        $product = Product::factory()->create(['price' => 50.00]);
        $pendingOrders = Order::factory()->count(3)->pending()->create(['user_id' => $this->user->id]);
        $confirmedOrders = Order::factory()->count(2)->confirmed()->create(['user_id' => $this->user->id]);

        foreach ($pendingOrders->concat($confirmedOrders) as $order) {
            OrderItem::factory()->count(2)->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
            ]);
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

        // Regardless of number of orders, query count should be at most 6 (count, orders+payments_count, eager user, eager items, eager payments)
        $this->assertLessThanOrEqual(6, count($queries));
    }

    public function test_user_can_update_order_status_and_items_using_product_ids(): void
    {
        $oldProduct = Product::factory()->create(['name' => 'Old Product', 'price' => 100.00]);
        $newProduct = Product::factory()->create(['name' => 'New Product', 'price' => 80.00]);

        $order = Order::factory()->pending()->create([
            'user_id' => $this->user->id,
            'total_amount' => 100.00,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $oldProduct->id,
            'product_name' => $oldProduct->name,
            'quantity' => 1,
            'unit_price' => 100.00,
            'subtotal' => 100.00,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/v1/orders/{$order->id}", [
                'status' => OrderStatusEnum::CONFIRMED->value,
                'items' => [
                    [
                        'product_id' => $newProduct->id,
                        'quantity' => 3, // 3 * 80.00 = 240.00
                    ],
                ],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', OrderStatusEnum::CONFIRMED->value)
            ->assertJsonPath('data.total_amount', 240)
            ->assertJsonPath('data.items.0.product_id', $newProduct->id)
            ->assertJsonPath('data.items.0.product_name', 'New Product');
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
