<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use App\Enums\OrderStatusEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize item `price` to `unit_price` and default customer details from authenticated user if omitted.
     */
    protected function prepareForValidation(): void
    {
        $user = $this->user('api');

        if ($user !== null) {
            if (! $this->filled('customer_name')) {
                $this->merge(['customer_name' => $user->name]);
            }
            if (! $this->filled('customer_email')) {
                $this->merge(['customer_email' => $user->email]);
            }
        }

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
            'customer_name' => ['required', 'string', 'min:2', 'max:255'],
            'customer_email' => ['required', 'string', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'shipping_address' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', 'string', Rule::in(OrderStatusEnum::values())],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_name' => ['required', 'string', 'min:1', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0.01', 'max:9999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'An order must contain at least one purchased item.',
            'items.min' => 'An order must contain at least one purchased item.',
            'items.*.product_name.required' => 'Each item must have a product name.',
            'items.*.quantity.required' => 'Each item must specify a quantity.',
            'items.*.quantity.min' => 'Item quantity must be at least 1.',
            'items.*.unit_price.required' => 'Each item must specify a price (or unit_price).',
            'items.*.unit_price.min' => 'Item price must be at least 0.01.',
            'status.in' => 'Invalid order status. Allowed values: ' . implode(', ', OrderStatusEnum::values()) . '.',
        ];
    }
}
