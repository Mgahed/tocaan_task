<?php

declare(strict_types=1);

namespace App\Services\Payments\Gateways;

use App\DTOs\Payment\PaymentPayloadDTO;
use App\DTOs\Payment\PaymentResultDTO;
use App\DTOs\Payment\WebhookEventDTO;
use App\Enums\PaymentStatusEnum;
use App\Models\Order;
use App\Services\Payments\Contracts\PaymentGatewayInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CreditCardGateway implements PaymentGatewayInterface
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config = []
    ) {}

    public function getIdentifier(): string
    {
        return 'credit_card';
    }

    /**
     * Simulate processing a Credit Card payment for the given order.
     */
    public function charge(Order $order, PaymentPayloadDTO $payload): PaymentResultDTO
    {
        $transactionId = 'cc_txn_' . Str::lower(Str::random(20));
        $cardNumber = (string) ($payload->paymentDetails['card_number'] ?? '4242424242424242');
        $lastFour = substr(preg_replace('/\D/', '', $cardNumber) ?: '4242', -4);

        // Determine simulated status
        if ($payload->simulateFailure || str_ends_with($lastFour, '0000')) {
            return new PaymentResultDTO(
                status: PaymentStatusEnum::FAILED,
                transactionId: $transactionId,
                gatewayResponse: [
                    'gateway' => $this->getIdentifier(),
                    'endpoint' => $this->config['endpoint'] ?? 'https://api.simulated-cc-gateway.local/v1/charges',
                    'card_last4' => $lastFour,
                    'currency' => $this->config['currency'] ?? 'USD',
                    'amount' => (float) $order->total_amount,
                    'error_code' => 'card_declined',
                    'message' => 'Simulated credit card decline.',
                    'processed_at' => now()->toIso8601String(),
                ],
                message: 'Credit card payment failed (declined).'
            );
        }

        if ($payload->simulatePending || str_ends_with($lastFour, '1111')) {
            return new PaymentResultDTO(
                status: PaymentStatusEnum::PENDING,
                transactionId: $transactionId,
                gatewayResponse: [
                    'gateway' => $this->getIdentifier(),
                    'endpoint' => $this->config['endpoint'] ?? 'https://api.simulated-cc-gateway.local/v1/charges',
                    'card_last4' => $lastFour,
                    'currency' => $this->config['currency'] ?? 'USD',
                    'amount' => (float) $order->total_amount,
                    'status_reason' => '3ds_authentication_pending',
                    'message' => 'Awaiting asynchronous webhook confirmation.',
                    'processed_at' => now()->toIso8601String(),
                ],
                message: 'Credit card payment is pending confirmation.'
            );
        }

        return new PaymentResultDTO(
            status: PaymentStatusEnum::SUCCESSFUL,
            transactionId: $transactionId,
            gatewayResponse: [
                'gateway' => $this->getIdentifier(),
                'endpoint' => $this->config['endpoint'] ?? 'https://api.simulated-cc-gateway.local/v1/charges',
                'card_last4' => $lastFour,
                'currency' => $this->config['currency'] ?? 'USD',
                'amount' => (float) $order->total_amount,
                'authorization_code' => strtoupper(Str::random(8)),
                'message' => 'Credit card charge approved.',
                'processed_at' => now()->toIso8601String(),
            ],
            message: 'Credit card payment processed successfully.'
        );
    }

    /**
     * Verify the HMAC-SHA256 webhook signature header (`X-Webhook-Signature`).
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        $signature = (string) $request->header('X-Webhook-Signature', '');
        $secret = (string) ($this->config['webhook_secret'] ?? '');

        if ($signature === '' || $secret === '') {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Parse an incoming Credit Card webhook payload into a normalized WebhookEventDTO.
     */
    public function parseWebhookPayload(Request $request): WebhookEventDTO
    {
        $payload = $request->all();
        $statusString = strtolower((string) ($payload['status'] ?? 'successful'));

        $targetStatus = match ($statusString) {
            'succeeded', 'successful', 'paid', 'completed' => PaymentStatusEnum::SUCCESSFUL,
            'failed', 'declined', 'canceled' => PaymentStatusEnum::FAILED,
            default => PaymentStatusEnum::PENDING,
        };

        return new WebhookEventDTO(
            gateway: $this->getIdentifier(),
            eventId: (string) ($payload['event_id'] ?? ''),
            eventType: (string) ($payload['event_type'] ?? 'charge.updated'),
            transactionId: (string) ($payload['transaction_id'] ?? ''),
            targetStatus: $targetStatus,
            rawPayload: $payload,
            paymentReference: isset($payload['payment_id']) ? (string) $payload['payment_id'] : null,
        );
    }
}
