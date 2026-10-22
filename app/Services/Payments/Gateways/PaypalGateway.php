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

class PaypalGateway implements PaymentGatewayInterface
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config = []
    ) {}

    public function getIdentifier(): string
    {
        return 'paypal';
    }

    /**
     * Simulate processing a PayPal payment for the given order.
     */
    public function charge(Order $order, PaymentPayloadDTO $payload): PaymentResultDTO
    {
        $transactionId = 'PAYPAL-' . strtoupper(Str::random(18));
        $payerEmail = (string) ($payload->paymentDetails['payer_email'] ?? $order->customer_email);

        if ($payload->simulateFailure || str_contains(strtolower($payerEmail), 'fail')) {
            return new PaymentResultDTO(
                status: PaymentStatusEnum::FAILED,
                transactionId: $transactionId,
                gatewayResponse: [
                    'gateway' => $this->getIdentifier(),
                    'mode' => $this->config['mode'] ?? 'sandbox',
                    'endpoint' => $this->config['endpoint'] ?? 'https://api-m.sandbox.paypal.com',
                    'payer_email' => $payerEmail,
                    'currency' => $this->config['currency'] ?? 'USD',
                    'amount' => (float) $order->total_amount,
                    'error' => 'INSTRUMENT_DECLINED',
                    'message' => 'Simulated PayPal funding instrument declined.',
                    'processed_at' => now()->toIso8601String(),
                ],
                message: 'PayPal payment failed.'
            );
        }

        if ($payload->simulatePending || str_contains(strtolower($payerEmail), 'pending')) {
            return new PaymentResultDTO(
                status: PaymentStatusEnum::PENDING,
                transactionId: $transactionId,
                gatewayResponse: [
                    'gateway' => $this->getIdentifier(),
                    'mode' => $this->config['mode'] ?? 'sandbox',
                    'endpoint' => $this->config['endpoint'] ?? 'https://api-m.sandbox.paypal.com',
                    'payer_email' => $payerEmail,
                    'currency' => $this->config['currency'] ?? 'USD',
                    'amount' => (float) $order->total_amount,
                    'status_reason' => 'PAYER_ACTION_REQUIRED',
                    'message' => 'PayPal payment pending webhook capture.',
                    'processed_at' => now()->toIso8601String(),
                ],
                message: 'PayPal payment is pending confirmation.'
            );
        }

        return new PaymentResultDTO(
            status: PaymentStatusEnum::SUCCESSFUL,
            transactionId: $transactionId,
            gatewayResponse: [
                'gateway' => $this->getIdentifier(),
                'mode' => $this->config['mode'] ?? 'sandbox',
                'endpoint' => $this->config['endpoint'] ?? 'https://api-m.sandbox.paypal.com',
                'payer_email' => $payerEmail,
                'currency' => $this->config['currency'] ?? 'USD',
                'amount' => (float) $order->total_amount,
                'capture_id' => 'CAP-' . strtoupper(Str::random(12)),
                'message' => 'PayPal payment captured successfully.',
                'processed_at' => now()->toIso8601String(),
            ],
            message: 'PayPal payment processed successfully.'
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
     * Parse an incoming PayPal webhook payload into a normalized WebhookEventDTO.
     */
    public function parseWebhookPayload(Request $request): WebhookEventDTO
    {
        $payload = $request->all();
        $statusString = strtolower((string) ($payload['status'] ?? 'successful'));

        $targetStatus = match ($statusString) {
            'completed', 'successful', 'approved', 'succeeded' => PaymentStatusEnum::SUCCESSFUL,
            'failed', 'denied', 'voided', 'declined' => PaymentStatusEnum::FAILED,
            default => PaymentStatusEnum::PENDING,
        };

        return new WebhookEventDTO(
            gateway: $this->getIdentifier(),
            eventId: (string) ($payload['event_id'] ?? ''),
            eventType: (string) ($payload['event_type'] ?? 'PAYMENT.CAPTURE.COMPLETED'),
            transactionId: (string) ($payload['transaction_id'] ?? ''),
            targetStatus: $targetStatus,
            rawPayload: $payload,
            paymentReference: isset($payload['payment_id']) ? (string) $payload['payment_id'] : null,
        );
    }
}
