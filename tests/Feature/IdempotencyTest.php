<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class IdempotencyTest extends TestCase
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

    public function test_replaying_payment_request_with_same_idempotency_key_returns_cached_response_without_duplicate_charge(): void
    {
        $order = Order::factory()->confirmed()->create([
            'user_id' => $this->user->id,
            'total_amount' => 180.00,
        ]);

        $idempotencyKey = (string) Str::uuid();
        $payload = [
            'payment_method' => 'credit_card',
            'payment_details' => ['card_number' => '4242424242424242'],
        ];

        $firstResponse = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson("/api/v1/orders/{$order->id}/payments", $payload);

        $firstResponse->assertStatus(201)
            ->assertHeader('X-Idempotent-Replay', 'false');

        $secondResponse = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson("/api/v1/orders/{$order->id}/payments", $payload);

        $secondResponse->assertStatus(201)
            ->assertHeader('X-Idempotent-Replay', 'true')
            ->assertJsonPath('data.payment_id', $firstResponse->json('data.payment_id'));

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_reusing_idempotency_key_with_different_payload_returns_422_error(): void
    {
        $idempotencyKey = (string) Str::uuid();

        $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/v1/orders', [
            'customer_name' => 'John',
            'customer_email' => 'john@example.com',
            'items' => [
                ['product_name' => 'Item A', 'quantity' => 1, 'price' => 50.00],
            ],
        ])->assertStatus(201);

        $mismatchedResponse = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/v1/orders', [
            'customer_name' => 'John',
            'customer_email' => 'john@example.com',
            'items' => [
                ['product_name' => 'Item B (Modified)', 'quantity' => 5, 'price' => 100.00],
            ],
        ]);

        $mismatchedResponse->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('orders', 1);
    }
}
