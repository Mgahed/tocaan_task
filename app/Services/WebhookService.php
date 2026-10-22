<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\Payment\WebhookEventDTO;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\UnsupportedPaymentGatewayException;
use App\Jobs\ProcessPaymentWebhookJob;
use App\Models\Payment;
use App\Models\WebhookEvent;
use App\Services\Payments\PaymentGatewayFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class WebhookService
{
    public function __construct(
        private readonly PaymentGatewayFactory $gatewayFactory
    ) {}

    /**
     * Validate webhook signature, guarantee event idempotency, verify target payment, and dispatch job to RabbitMQ.
     *
     * @return array<string, mixed>
     * @throws BusinessRuleException
     * @throws UnsupportedPaymentGatewayException
     */
    public function handlePaymentWebhook(string $gatewayIdentifier, Request $request): array
    {
        // 1. Resolve the payment gateway strategy
        $gateway = $this->gatewayFactory->make($gatewayIdentifier);

        // 2. Check that X-Webhook-Signature header is present
        $signatureHeader = trim((string) $request->header('X-Webhook-Signature', ''));
        if ($signatureHeader === '') {
            throw new BusinessRuleException('Missing X-Webhook-Signature header.', 401);
        }

        // 3. Verify cryptographic HMAC-SHA256 signature
        if (! $gateway->verifyWebhookSignature($request)) {
            throw new BusinessRuleException('Invalid webhook signature.', 401);
        }

        // 4. Parse normalized webhook payload
        $dto = $gateway->parseWebhookPayload($request);

        if ($dto->eventId === '') {
            throw new BusinessRuleException('Webhook payload is missing required event_id.', 422);
        }

        if ($dto->transactionId === '' && ($dto->paymentReference === null || $dto->paymentReference === '')) {
            throw new BusinessRuleException('Webhook payload must contain transaction_id or payment_id.', 422);
        }

        // 5. Acquire atomic lock per gateway + event_id to prevent race conditions from concurrent webhook retries
        $lockKey = "webhook_lock:{$dto->gateway}:{$dto->eventId}";
        $lock = Cache::lock($lockKey, 10);

        return $lock->block(5, function () use ($dto): array {
            // 6. Check if this event_id was already received for this gateway (Idempotency)
            $existing = WebhookEvent::query()
                ->where('gateway', $dto->gateway)
                ->where('event_id', $dto->eventId)
                ->first();

            if ($existing !== null) {
                return [
                    'duplicate' => true,
                    'event_id' => $existing->event_id,
                    'gateway' => $existing->gateway,
                    'status' => $existing->status,
                ];
            }

            // 7. Locate the target Payment record so invalid transaction_id / payment_id returns 404 immediately
            $payment = $this->findMatchingPayment($dto);

            if ($payment === null) {
                throw new BusinessRuleException(
                    'Matching payment record not found for the provided transaction_id or payment_id.',
                    404
                );
            }

            $webhookEvent = WebhookEvent::create([
                'gateway' => $dto->gateway,
                'event_id' => $dto->eventId,
                'event_type' => $dto->eventType,
                'payload' => $dto->rawPayload,
                'status' => 'pending',
            ]);

            // 8. Dispatch asynchronous processing job to RabbitMQ (`payment_webhooks` queue)
            ProcessPaymentWebhookJob::dispatch(
                webhookEventId: $webhookEvent->id,
                targetStatus: $dto->targetStatus->value,
                paymentId: $payment->id,
                transactionId: $payment->transaction_id,
                paymentReference: $payment->payment_reference,
            )->onQueue('payment_webhooks');

            // Refresh webhook event in case sync queue processed it immediately during tests
            $webhookEvent->refresh();

            return [
                'duplicate' => false,
                'event_id' => $webhookEvent->event_id,
                'gateway' => $webhookEvent->gateway,
                'payment_id' => $payment->payment_reference,
                'order_id' => $payment->order_id,
                'target_status' => $dto->targetStatus->value,
                'status' => $webhookEvent->status,
            ];
        });
    }

    /**
     * Find a Payment record matching either transaction_id or payment_reference (or numeric id).
     */
    private function findMatchingPayment(WebhookEventDTO $dto): ?Payment
    {
        if ($dto->transactionId !== '') {
            $byTxn = Payment::query()->where('transaction_id', $dto->transactionId)->first();
            if ($byTxn !== null) {
                return $byTxn;
            }
        }

        if ($dto->paymentReference !== null && $dto->paymentReference !== '') {
            $byRef = Payment::query()->where('payment_reference', $dto->paymentReference)->first();
            if ($byRef !== null) {
                return $byRef;
            }

            if (ctype_digit($dto->paymentReference)) {
                return Payment::query()->find((int) $dto->paymentReference);
            }
        }

        return null;
    }
}
