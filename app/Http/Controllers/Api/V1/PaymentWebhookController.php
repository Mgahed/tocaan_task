<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\WebhookService;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentWebhookController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private readonly WebhookService $webhookService
    ) {}

    /**
     * Handle incoming asynchronous payment gateway webhooks.
     */
    public function handle(Request $request, string $gateway): JsonResponse
    {
        $result = $this->webhookService->handlePaymentWebhook($gateway, $request);

        $message = $result['duplicate']
            ? 'Webhook event already received and ignored idempotently.'
            : 'Webhook event verified and queued for processing.';

        return $this->successResponse($result, $message, 200);
    }
}
