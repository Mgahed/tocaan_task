<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderStatusEnum: string
{
    case PENDING = 'pending';
    case CONFIRMED = 'confirmed';
    case CANCELLED = 'cancelled';

    /**
     * Get all enum values as an array of strings.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Check if the order status allows payment processing.
     */
    public function canProcessPayment(): bool
    {
        return $this === self::CONFIRMED;
    }
}
