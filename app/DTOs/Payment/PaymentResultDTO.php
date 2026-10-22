<?php

declare(strict_types=1);

namespace App\DTOs\Payment;

use App\Enums\PaymentStatusEnum;

readonly class PaymentResultDTO
{
    /**
     * @param array<string, mixed> $gatewayResponse
     */
    public function __construct(
        public PaymentStatusEnum $status,
        public string $transactionId,
        public array $gatewayResponse = [],
        public ?string $message = null,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->status === PaymentStatusEnum::SUCCESSFUL;
    }
}
