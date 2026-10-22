<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PaymentProcessedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    public function __construct(
        public readonly int $paymentId
    ) {}

    /**
     * Execute the job consumed from RabbitMQ (`payments` queue).
     */
    public function handle(): void
    {
        $payment = Payment::with('order')->find($this->paymentId);

        if ($payment === null) {
            return;
        }

        Log::info('PaymentProcessedJob handled via RabbitMQ queue.', [
            'payment_id' => $payment->payment_reference,
            'order_id' => $payment->order_id,
            'method' => $payment->payment_method,
            'status' => $payment->status->value,
            'amount' => $payment->amount,
        ]);
    }
}
