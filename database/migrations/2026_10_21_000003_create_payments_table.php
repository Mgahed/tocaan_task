<?php

declare(strict_types=1);

use App\Enums\PaymentStatusEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('payment_reference')->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            // Stored as string rather than DB-level enum so new payment gateways can be added without schema migrations
            $table->string('payment_method', 50)->index();
            $table->string('status', 30)->default(PaymentStatusEnum::PENDING->value)->index();
            $table->decimal('amount', 12, 2);
            $table->string('transaction_id')->nullable()->unique();
            $table->json('gateway_response')->nullable();
            $table->string('idempotency_key', 128)->nullable()->unique();
            $table->timestamps();

            $table->index(['order_id', 'status'], 'payments_order_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
