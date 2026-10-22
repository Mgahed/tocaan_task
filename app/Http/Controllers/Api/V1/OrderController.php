<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\Order\OrderDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\ListOrderRequest;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Requests\Order\UpdateOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\User;
use App\Services\OrderService;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class OrderController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private readonly OrderService $orderService
    ) {}

    /**
     * Retrieve paginated list of orders with optional status filter.
     */
    public function index(ListOrderRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::guard('api')->user();
        $validated = $request->validated();

        $paginator = $this->orderService->listUserOrders(
            userId: $user->id,
            status: $validated['status'] ?? null,
            perPage: (int) ($validated['per_page'] ?? 15),
            page: (int) ($validated['page'] ?? 1)
        );

        return $this->paginatedResponse(
            OrderResource::collection($paginator->getCollection()),
            $paginator,
            'Orders retrieved successfully.'
        );
    }

    /**
     * Create a new order with items and calculated total.
     */
    public function store(StoreOrderRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::guard('api')->user();

        $dto = OrderDTO::fromRequest($user->id, $request->validated());
        $order = $this->orderService->createOrder($dto);

        return $this->successResponse(
            new OrderResource($order),
            'Order created successfully.',
            201
        );
    }

    /**
     * Display a specific order with its items and payments.
     */
    public function show(int $order): JsonResponse
    {
        /** @var User $user */
        $user = Auth::guard('api')->user();

        $orderModel = $this->orderService->getOrderForUser($order, $user->id);

        return $this->successResponse(
            new OrderResource($orderModel),
            'Order details retrieved successfully.'
        );
    }

    /**
     * Update an existing order's details, status, or items.
     */
    public function update(UpdateOrderRequest $request, int $order): JsonResponse
    {
        /** @var User $user */
        $user = Auth::guard('api')->user();

        $updatedOrder = $this->orderService->updateOrder(
            $order,
            $user->id,
            $request->validated()
        );

        return $this->successResponse(
            new OrderResource($updatedOrder),
            'Order updated successfully.'
        );
    }

    /**
     * Delete an order (only if no payments are associated).
     */
    public function destroy(int $order): JsonResponse
    {
        /** @var User $user */
        $user = Auth::guard('api')->user();

        $this->orderService->deleteOrder($order, $user->id);

        return $this->successResponse(
            null,
            'Order deleted successfully.'
        );
    }
}
