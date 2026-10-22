<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentMethodEnum;
use App\Enums\PaymentStatusEnum;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_reference' => (string) Str::uuid(),
            'order_id' => Order::factory()->confirmed(),
            'payment_method' => fake()->randomElement(PaymentMethodEnum::values()),
            'status' => PaymentStatusEnum::SUCCESSFUL,
            'amount' => fake()->randomFloat(2, 25, 1500),
            'transaction_id' => 'txn_' . Str::lower(Str::random(16)),
            'gateway_response' => [
                'simulated' => true,
                'timestamp' => now()->toIso8601String(),
            ],
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    /**
     * Indicate that the payment is pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PaymentStatusEnum::PENDING,
        ]);
    }

    /**
     * Indicate that the payment is successful.
     */
    public function successful(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PaymentStatusEnum::SUCCESSFUL,
        ]);
    }

    /**
     * Indicate that the payment failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PaymentStatusEnum::FAILED,
        ]);
    }
}
