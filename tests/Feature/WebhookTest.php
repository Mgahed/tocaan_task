<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PaymentStatusEnum;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_webhook_signature_updates_payment_status_and_handles_duplicates_idempotently(): void
    {
        $secret = 'whsec_test_credit_card_secret';
        config(['payment.gateways.credit_card.webhook_secret' => $secret]);

        $user = User::factory()->create();
        $order = Order::factory()->confirmed()->create(['user_id' => $user->id, 'total_amount' => 300.00]);
        $payment = Payment::factory()->pending()->create([
            'order_id' => $order->id,
            'payment_method' => 'credit_card',
            'transaction_id' => 'cc_txn_webhook_001',
            'amount' => 300.00,
        ]);

        $payloadArray = [
            'event_id' => 'evt_cc_unique_001',
            'event_type' => 'charge.succeeded',
            'transaction_id' => 'cc_txn_webhook_001',
            'status' => 'successful',
        ];
        $rawJson = json_encode($payloadArray, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $rawJson, $secret);

        // 1. First webhook delivery
        $response = $this->call(
            'POST',
            '/api/v1/webhooks/payments/credit_card',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
            ],
            $rawJson
        );

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.duplicate', false)
            ->assertJsonPath('data.payment_id', $payment->payment_reference)
            ->assertJsonPath('data.target_status', PaymentStatusEnum::SUCCESSFUL->value);

        // Verify payment status transitioned from pending -> successful
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatusEnum::SUCCESSFUL->value,
        ]);

        $this->assertDatabaseHas('webhook_events', [
            'gateway' => 'credit_card',
            'event_id' => 'evt_cc_unique_001',
            'status' => 'processed',
        ]);

        // 2. Replay the exact same webhook event (idempotency check)
        $duplicateResponse = $this->call(
            'POST',
            '/api/v1/webhooks/payments/credit_card',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
            ],
            $rawJson
        );

        $duplicateResponse->assertStatus(200)
            ->assertJsonPath('data.duplicate', true);

        $this->assertDatabaseCount('webhook_events', 1);
    }

    public function test_webhook_with_invalid_signature_is_rejected_with_401(): void
    {
        config(['payment.gateways.credit_card.webhook_secret' => 'whsec_real_secret']);

        $rawJson = json_encode([
            'event_id' => 'evt_tampered_001',
            'transaction_id' => 'cc_txn_001',
            'status' => 'successful',
        ], JSON_THROW_ON_ERROR);

        $response = $this->call(
            'POST',
            '/api/v1/webhooks/payments/credit_card',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_WEBHOOK_SIGNATURE' => 'forged_invalid_signature',
            ],
            $rawJson
        );

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Invalid webhook signature.');
    }

    public function test_webhook_with_missing_signature_header_is_rejected_with_401(): void
    {
        config(['payment.gateways.credit_card.webhook_secret' => 'whsec_real_secret']);

        $response = $this->postJson('/api/v1/webhooks/payments/credit_card', [
            'event_id' => 'evt_missing_sig_001',
            'transaction_id' => 'cc_txn_001',
            'status' => 'successful',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Missing X-Webhook-Signature header.');
    }

    public function test_webhook_with_unknown_transaction_id_returns_404(): void
    {
        $secret = 'whsec_test_credit_card_secret';
        config(['payment.gateways.credit_card.webhook_secret' => $secret]);

        $rawJson = json_encode([
            'event_id' => 'evt_unknown_txn_001',
            'transaction_id' => 'cc_txn_does_not_exist_999',
            'status' => 'successful',
        ], JSON_THROW_ON_ERROR);

        $signature = hash_hmac('sha256', $rawJson, $secret);

        $response = $this->call(
            'POST',
            '/api/v1/webhooks/payments/credit_card',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
            ],
            $rawJson
        );

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }
}
