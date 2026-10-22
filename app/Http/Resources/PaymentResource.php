<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Payment
 */
class PaymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_id' => $this->payment_reference,
            'order_id' => $this->order_id,
            'payment_method' => $this->payment_method,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'amount' => (float) $this->amount,
            'transaction_id' => $this->transaction_id,
            'gateway_response' => $this->gateway_response,
            'order' => new OrderResource($this->whenLoaded('order')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
