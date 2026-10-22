<?php

declare(strict_types=1);

namespace App\DTOs\Payment;

use App\Enums\PaymentStatusEnum;

readonly class WebhookEventDTO
{
    /**
     * @param array<string, mixed> $rawPayload
     */
    public function __construct(
        public string $gateway,
        public string $eventId,
        public string $eventType,
        public string $transactionId,
        public PaymentStatusEnum $targetStatus,
        public array $rawPayload = [],
        public ?string $paymentReference = null,
    ) {}
}
