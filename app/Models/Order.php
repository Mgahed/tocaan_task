<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatusEnum;
use App\Enums\PaymentStatusEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    /** @use HasFactory<\Database\Factories\OrderFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'status',
        'total_amount',
        'shipping_address',
        'notes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatusEnum::class,
            'total_amount' => 'decimal:2',
        ];
    }

    /**
     * Get the user who owns the order.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the line items for the order.
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Get the payments associated with the order.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Scope a query to filter orders by status.
     */
    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        if ($status !== null && $status !== '') {
            return $query->where('status', $status);
        }

        return $query;
    }

    /**
     * Determine if the order is in the confirmed state.
     */
    public function isConfirmed(): bool
    {
        return $this->status === OrderStatusEnum::CONFIRMED;
    }

    /**
     * Determine if the order has any associated payments.
     */
    public function hasPayments(): bool
    {
        if (array_key_exists('payments_count', $this->attributes)) {
            return (int) $this->attributes['payments_count'] > 0;
        }

        if ($this->relationLoaded('payments')) {
            return $this->payments->isNotEmpty();
        }

        return $this->payments()->exists();
    }

    /**
     * Determine if the order already has a successful payment.
     */
    public function hasSuccessfulPayment(): bool
    {
        if ($this->relationLoaded('payments')) {
            return $this->payments->contains(
                fn (Payment $payment): bool => $payment->status === PaymentStatusEnum::SUCCESSFUL
            );
        }

        return $this->payments()
            ->where('status', PaymentStatusEnum::SUCCESSFUL->value)
            ->exists();
    }
}
