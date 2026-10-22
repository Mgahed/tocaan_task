<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\UnsupportedPaymentGatewayException;
use App\Jobs\ProcessPaymentWebhookJob;
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
     * Validate webhook signature, guarantee event idempotency, and dispatch processing job to RabbitMQ.
     *
     * @return array{duplicate: bool, event_id: string, gateway: string, status: string}
     * @throws BusinessRuleException
     * @throws UnsupportedPaymentGatewayException
     */
    public function handlePaymentWebhook(string $gatewayIdentifier, Request $request): array
    {
        // 1. Resolve the payment gateway strategy
        $gateway = $this->gatewayFactory->make($gatewayIdentifier);

        // 2. Verify cryptographic HMAC signature
        if (! $gateway->verifyWebhookSignature($request)) {
            throw new BusinessRuleException('Invalid webhook signature.', 401);
        }

        // 3. Parse normalized webhook payload
        $dto = $gateway->parseWebhookPayload($request);

        if ($dto->eventId === '') {
            throw new BusinessRuleException('Webhook payload is missing required event_id.', 422);
        }

        if ($dto->transactionId === '' && ($dto->paymentReference === null || $dto->paymentReference === '')) {
            throw new BusinessRuleException('Webhook payload must contain transaction_id or payment_id.', 422);
        }

        // 4. Acquire atomic lock per gateway + event_id to prevent race conditions from concurrent webhook retries
        $lockKey = "webhook_lock:{$dto->gateway}:{$dto->eventId}";
        $lock = Cache::lock($lockKey, 10);

        return $lock->block(5, function () use ($dto): array {
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

            $webhookEvent = WebhookEvent::create([
                'gateway' => $dto->gateway,
                'event_id' => $dto->eventId,
                'event_type' => $dto->eventType,
                'payload' => $dto->rawPayload,
                'status' => 'pending',
            ]);

            // 5. Dispatch asynchronous processing job to RabbitMQ (`payment_webhooks` queue)
            ProcessPaymentWebhookJob::dispatch(
                webhookEventId: $webhookEvent->id,
                targetStatus: $dto->targetStatus->value,
                transactionId: $dto->transactionId !== '' ? $dto->transactionId : null,
                paymentReference: $dto->paymentReference,
            )->onQueue('payment_webhooks');

            return [
                'duplicate' => false,
                'event_id' => $webhookEvent->event_id,
                'gateway' => $webhookEvent->gateway,
                'status' => $webhookEvent->status,
            ];
        });
    }
}
