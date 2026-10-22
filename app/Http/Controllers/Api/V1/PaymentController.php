<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\Payment\PaymentPayloadDTO;
use App\Enums\PaymentStatusEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\ListPaymentRequest;
use App\Http\Requests\Payment\ProcessPaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\User;
use App\Services\PaymentService;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PaymentController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private readonly PaymentService $paymentService
    ) {}

    /**
     * Retrieve a paginated list of all payments for the authenticated user.
     */
    public function index(ListPaymentRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::guard('api')->user();
        $validated = $request->validated();

        $paginator = $this->paymentService->listUserPayments(
            userId: $user->id,
            status: $validated['status'] ?? null,
            method: $validated['payment_method'] ?? null,
            perPage: (int) ($validated['per_page'] ?? 15),
            page: (int) ($validated['page'] ?? 1)
        );

        return $this->paginatedResponse(
            PaymentResource::collection($paginator->getCollection()),
            $paginator,
            'Payments retrieved successfully.'
        );
    }

    /**
     * Retrieve a paginated list of payments for a specific order.
     */
    public function orderPayments(Request $request, int $order): JsonResponse
    {
        /** @var User $user */
        $user = Auth::guard('api')->user();

        $perPage = max(1, min(100, (int) $request->query('per_page', 15)));
        $page = max(1, (int) $request->query('page', 1));

        $paginator = $this->paymentService->listOrderPayments(
            orderId: $order,
            userId: $user->id,
            perPage: $perPage,
            page: $page
        );

        return $this->paginatedResponse(
            PaymentResource::collection($paginator->getCollection()),
            $paginator,
            'Order payments retrieved successfully.'
        );
    }

    /**
     * Process a payment for a confirmed order using the selected payment gateway strategy.
     */
    public function store(ProcessPaymentRequest $request, int $order): JsonResponse
    {
        /** @var User $user */
        $user = Auth::guard('api')->user();

        $idempotencyKey = $request->header('Idempotency-Key');
        $payload = PaymentPayloadDTO::fromRequest($request->validated(), $idempotencyKey);

        $payment = $this->paymentService->processPayment(
            orderId: $order,
            userId: $user->id,
            payload: $payload
        );

        $message = match ($payment->status) {
            PaymentStatusEnum::SUCCESSFUL => 'Payment processed successfully.',
            PaymentStatusEnum::PENDING => 'Payment initiated and is pending confirmation.',
            PaymentStatusEnum::FAILED => 'Payment processing failed.',
        };

        return $this->successResponse(
            new PaymentResource($payment),
            $message,
            201
        );
    }
}
