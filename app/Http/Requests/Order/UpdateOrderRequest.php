<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use App\Enums\OrderStatusEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'shipping_address' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', 'required', 'string', Rule::in(OrderStatusEnum::values())],
            'items' => ['sometimes', 'required', 'array', 'min:1'],
            'items.*.product_id' => ['required_with:items', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1', 'max:10000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => 'Invalid order status. Allowed values: ' . implode(', ', OrderStatusEnum::values()) . '.',
            'items.min' => 'If updating items, at least one item must be provided.',
            'items.*.product_id.required_with' => 'Each item must specify a valid product_id.',
            'items.*.product_id.distinct' => 'Duplicate product_id entries are not allowed; combine quantities instead.',
            'items.*.product_id.exists' => 'One or more selected products do not exist in our database.',
            'items.*.quantity.required_with' => 'Each item must specify a quantity.',
            'items.*.quantity.min' => 'Item quantity must be at least 1.',
        ];
    }
}
