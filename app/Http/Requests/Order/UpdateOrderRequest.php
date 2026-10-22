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
     * Normalize item `price` to `unit_price` if provided.
     */
    protected function prepareForValidation(): void
    {
        if (is_array($this->input('items'))) {
            $normalizedItems = [];
            foreach ($this->input('items') as $item) {
                if (is_array($item) && isset($item['price']) && ! isset($item['unit_price'])) {
                    $item['unit_price'] = $item['price'];
                }
                $normalizedItems[] = $item;
            }
            $this->merge(['items' => $normalizedItems]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'customer_name' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'customer_email' => ['sometimes', 'required', 'string', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'shipping_address' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', 'required', 'string', Rule::in(OrderStatusEnum::values())],
            'items' => ['sometimes', 'required', 'array', 'min:1'],
            'items.*.product_name' => ['required_with:items', 'string', 'min:1', 'max:255'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1', 'max:10000'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'min:0.01', 'max:9999999.99'],
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
            'items.*.product_name.required_with' => 'Each item must have a product name.',
            'items.*.quantity.required_with' => 'Each item must specify a quantity.',
            'items.*.unit_price.required_with' => 'Each item must specify a price (or unit_price).',
        ];
    }
}
