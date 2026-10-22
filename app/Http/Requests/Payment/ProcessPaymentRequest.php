<?php

declare(strict_types=1);

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class ProcessPaymentRequest extends FormRequest
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
            'payment_method' => ['required', 'string', 'max:50'],
            'payment_details' => ['nullable', 'array'],
            'payment_details.card_number' => ['nullable', 'string', 'max:32'],
            'payment_details.expiry_month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'payment_details.expiry_year' => ['nullable', 'integer', 'min:2025', 'max:2099'],
            'payment_details.cvv' => ['nullable', 'string', 'min:3', 'max:4'],
            'payment_details.payer_email' => ['nullable', 'email', 'max:255'],
            'simulate_failure' => ['nullable', 'boolean'],
            'simulate_pending' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payment_method.required' => 'Please specify a payment_method (e.g. credit_card, paypal).',
        ];
    }
}
