<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PaymentStatusEnum;
use App\Jobs\PaymentProcessedJob;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PaymentTest extends TestCase
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

    public function test_it_processes_credit_card_and_paypal_payments_for_confirmed_orders(): void
    {
        Queue::fake();

        $order1 = Order::factory()->confirmed()->create([
            'user_id' => $this->user->id,
            'total_amount' => 250.00,
        ]);

        $ccResponse = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/orders/{$order1->id}/payments", [
                'payment_method' => 'credit_card',
                'payment_details' => [
                    'card_number' => '4242424242424242',
                    'expiry_month' => 12,
                    'expiry_year' => 2028,
                    'cvv' => '123',
                ],
            ]);

        $ccResponse->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.payment_method', 'credit_card')
            ->assertJsonPath('data.status', PaymentStatusEnum::SUCCESSFUL->value)
            ->assertJsonPath('data.amount', 250);

        Queue::assertPushedOn('payments', PaymentProcessedJob::class);

        $order2 = Order::factory()->confirmed()->create([
            'user_id' => $this->user->id,
            'total_amount' => 99.95,
        ]);

        $ppResponse = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/orders/{$order2->id}/payments", [
                'payment_method' => 'paypal',
                'payment_details' => [
                    'payer_email' => 'payer@example.com',
                ],
            ]);

        $ppResponse->assertStatus(201)
            ->assertJsonPath('data.payment_method', 'paypal')
            ->assertJsonPath('data.status', PaymentStatusEnum::SUCCESSFUL->value);
    }

    public function test_payment_is_rejected_if_order_is_not_in_confirmed_status(): void
    {
        $pendingOrder = Order::factory()->pending()->create(['user_id' => $this->user->id]);
        $cancelledOrder = Order::factory()->cancelled()->create(['user_id' => $this->user->id]);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/orders/{$pendingOrder->id}/payments", [
                'payment_method' => 'credit_card',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/orders/{$cancelledOrder->id}/payments", [
                'payment_method' => 'paypal',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_it_prevents_double_payment_for_already_paid_order(): void
    {
        $order = Order::factory()->confirmed()->create(['user_id' => $this->user->id]);
        Payment::factory()->successful()->create(['order_id' => $order->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/orders/{$order->id}/payments", [
                'payment_method' => 'credit_card',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'This order has already been paid successfully.');
    }

    public function test_user_can_view_all_payments_and_order_specific_payments(): void
    {
        $order = Order::factory()->confirmed()->create(['user_id' => $this->user->id]);
        Payment::factory()->count(2)->create(['order_id' => $order->id]);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/v1/payments')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/v1/orders/{$order->id}/payments")
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }
}
