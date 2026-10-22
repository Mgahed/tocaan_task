<?php

declare(strict_types=1);

namespace App\Services\Payments\Contracts;

use App\DTOs\Payment\PaymentPayloadDTO;
use App\DTOs\Payment\PaymentResultDTO;
use App\DTOs\Payment\WebhookEventDTO;
use App\Models\Order;
use Illuminate\Http\Request;

interface PaymentGatewayInterface
{
    /**
     * Return the unique identifier for this payment gateway (e.g. "credit_card", "paypal").
     */
    public function getIdentifier(): string;

    /**
     * Process/simulate a payment charge for a confirmed order.
     */
    public function charge(Order $order, PaymentPayloadDTO $payload): PaymentResultDTO;

    /**
     * Verify the cryptographic signature of an incoming webhook request.
     */
    public function verifyWebhookSignature(Request $request): bool;

    /**
     * Parse an incoming webhook request into a normalized WebhookEventDTO.
     */
    public function parseWebhookPayload(Request $request): WebhookEventDTO;
}
