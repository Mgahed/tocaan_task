<?php

declare(strict_types=1);

namespace App\DTOs\Payment;

readonly class PaymentPayloadDTO
{
    /**
     * @param array<string, mixed> $paymentDetails
     */
    public function __construct(
        public string $paymentMethod,
        public array $paymentDetails = [],
        public ?string $idempotencyKey = null,
        public bool $simulateFailure = false,
        public bool $simulatePending = false,
    ) {}

    /**
     * @param array<string, mixed> $validated
     */
    public static function fromRequest(array $validated, ?string $idempotencyKey = null): self
    {
        $details = is_array($validated['payment_details'] ?? null)
            ? $validated['payment_details']
            : [];

        return new self(
            paymentMethod: strtolower(trim((string) $validated['payment_method'])),
            paymentDetails: $details,
            idempotencyKey: $idempotencyKey,
            simulateFailure: (bool) ($validated['simulate_failure'] ?? ($details['simulate_failure'] ?? false)),
            simulatePending: (bool) ($validated['simulate_pending'] ?? ($details['simulate_pending'] ?? false)),
        );
    }
}
