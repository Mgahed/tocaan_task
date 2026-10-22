<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentStatusEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Payment extends Model
{
    /** @use HasFactory<\Database\Factories\PaymentFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'payment_reference',
        'order_id',
        'payment_method',
        'status',
        'amount',
        'transaction_id',
        'gateway_response',
        'idempotency_key',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PaymentStatusEnum::class,
            'amount' => 'decimal:2',
            'gateway_response' => 'array',
        ];
    }

    /**
     * Automatically assign a UUID payment_reference when creating a payment.
     */
    protected static function booted(): void
    {
        static::creating(function (Payment $payment): void {
            if (empty($payment->payment_reference)) {
                $payment->payment_reference = (string) Str::uuid();
            }
        });
    }

    /**
     * Get the order associated with the payment.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Scope a query to filter payments by status.
     */
    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        if ($status !== null && $status !== '') {
            return $query->where('status', $status);
        }

        return $query;
    }

    /**
     * Scope a query to filter payments by payment method.
     */
    public function scopeMethod(Builder $query, ?string $method): Builder
    {
        if ($method !== null && $method !== '') {
            return $query->where('payment_method', $method);
        }

        return $query;
    }
}
