<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    use ApiResponseTrait;

    /**
     * List active catalog products (paginated).
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min(100, (int) $request->query('per_page', 15)));
        $page = max(1, (int) $request->query('page', 1));

        $paginator = Product::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return $this->paginatedResponse(
            ProductResource::collection($paginator->getCollection()),
            $paginator,
            'Products retrieved successfully.'
        );
    }
}
