<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\PaymentStatusEnum;
use App\Models\Payment;
use App\Models\WebhookEvent;
use App\Services\OrderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessPaymentWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    public function __construct(
        public readonly int $webhookEventId,
        public readonly string $targetStatus,
        public readonly ?int $paymentId = null,
        public readonly ?string $transactionId = null,
        public readonly ?string $paymentReference = null,
    ) {}

    /**
     * Execute the webhook event processing job consumed from RabbitMQ (`payment_webhooks` queue).
     */
    public function handle(OrderService $orderService): void
    {
        $userIdToInvalidate = null;
        $orderIdToInvalidate = null;

        try {
            DB::transaction(function () use (&$userIdToInvalidate, &$orderIdToInvalidate): void {
                /** @var WebhookEvent|null $webhookEvent */
                $webhookEvent = WebhookEvent::query()
                    ->where('id', $this->webhookEventId)
                    ->lockForUpdate()
                    ->first();

                if ($webhookEvent === null || $webhookEvent->status === 'processed') {
                    return;
                }

                // Locate the corresponding Payment row using pessimistic locking
                $paymentQuery = Payment::query()->lockForUpdate();

                if ($this->paymentId !== null) {
                    $paymentQuery->where('id', $this->paymentId);
                } elseif ($this->transactionId !== null && $this->transactionId !== '') {
                    $paymentQuery->where('transaction_id', $this->transactionId);
                } elseif ($this->paymentReference !== null && $this->paymentReference !== '') {
                    $paymentQuery->where('payment_reference', $this->paymentReference);
                } else {
                    $webhookEvent->update([
                        'status' => 'failed',
                        'error_message' => 'Missing payment identifier in webhook payload.',
                        'processed_at' => now(),
                    ]);

                    return;
                }

                /** @var Payment|null $payment */
                $payment = $paymentQuery->with('order')->first();

                if ($payment === null) {
                    $webhookEvent->update([
                        'status' => 'failed',
                        'error_message' => 'Matching payment record not found.',
                        'processed_at' => now(),
                    ]);

                    return;
                }

                $newStatus = PaymentStatusEnum::tryFrom($this->targetStatus) ?? PaymentStatusEnum::PENDING;

                $existingResponse = is_array($payment->gateway_response) ? $payment->gateway_response : [];
                $existingResponse['webhook_last_event'] = [
                    'event_id' => $webhookEvent->event_id,
                    'event_type' => $webhookEvent->event_type,
                    'status' => $newStatus->value,
                    'processed_at' => now()->toIso8601String(),
                ];

                $payment->update([
                    'status' => $newStatus,
                    'gateway_response' => $existingResponse,
                ]);

                $webhookEvent->update([
                    'status' => 'processed',
                    'processed_at' => now(),
                ]);

                if ($payment->order !== null) {
                    $userIdToInvalidate = $payment->order->user_id;
                    $orderIdToInvalidate = $payment->order->id;
                }
            });

            if ($userIdToInvalidate !== null) {
                $orderService->invalidateOrderCache($userIdToInvalidate, $orderIdToInvalidate);
            }
        } catch (Throwable $e) {
            Log::error('Failed to process payment webhook job.', [
                'webhook_event_id' => $this->webhookEventId,
                'error' => $e->getMessage(),
            ]);

            WebhookEvent::query()->where('id', $this->webhookEventId)->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
