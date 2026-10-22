<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTOs\Payment\PaymentPayloadDTO;
use App\Enums\PaymentStatusEnum;
use App\Models\Order;
use App\Services\Payments\Gateways\CreditCardGateway;
use App\Services\Payments\Gateways\PaypalGateway;
use Illuminate\Http\Request;
use Tests\TestCase;

class PaymentGatewaysTest extends TestCase
{
    public function test_credit_card_gateway_processes_successful_and_failed_charges(): void
    {
        $gateway = new CreditCardGateway([
            'endpoint' => 'https://api.simulated-cc.local/v1/charges',
            'currency' => 'USD',
            'webhook_secret' => 'test_cc_secret',
        ]);

        $order = new Order(['total_amount' => 199.99]);

        // Successful charge
        $successPayload = new PaymentPayloadDTO(
            paymentMethod: 'credit_card',
            paymentDetails: ['card_number' => '4242424242424242']
        );
        $successResult = $gateway->charge($order, $successPayload);

        $this->assertSame(PaymentStatusEnum::SUCCESSFUL, $successResult->status);
        $this->assertStringStartsWith('cc_txn_', $successResult->transactionId);
        $this->assertSame('4242', $successResult->gatewayResponse['card_last4']);

        // Failed charge via card ending in 0000
        $failPayload = new PaymentPayloadDTO(
            paymentMethod: 'credit_card',
            paymentDetails: ['card_number' => '4242424242420000']
        );
        $failResult = $gateway->charge($order, $failPayload);

        $this->assertSame(PaymentStatusEnum::FAILED, $failResult->status);
        $this->assertSame('card_declined', $failResult->gatewayResponse['error_code']);
    }

    public function test_paypal_gateway_processes_successful_and_pending_charges(): void
    {
        $gateway = new PaypalGateway([
            'mode' => 'sandbox',
            'currency' => 'USD',
            'webhook_secret' => 'test_pp_secret',
        ]);

        $order = new Order(['total_amount' => 350.00]);

        // Successful charge
        $successPayload = new PaymentPayloadDTO(
            paymentMethod: 'paypal',
            paymentDetails: ['payer_email' => 'customer@example.com']
        );
        $successResult = $gateway->charge($order, $successPayload);

        $this->assertSame(PaymentStatusEnum::SUCCESSFUL, $successResult->status);
        $this->assertStringStartsWith('PAYPAL-', $successResult->transactionId);

        // Pending charge
        $pendingPayload = new PaymentPayloadDTO(
            paymentMethod: 'paypal',
            paymentDetails: ['payer_email' => 'customer@example.com'],
            simulatePending: true
        );
        $pendingResult = $gateway->charge($order, $pendingPayload);

        $this->assertSame(PaymentStatusEnum::PENDING, $pendingResult->status);
    }

    public function test_gateway_verifies_webhook_hmac_signature_correctly(): void
    {
        $secret = 'whsec_unit_test_secret';
        $gateway = new CreditCardGateway(['webhook_secret' => $secret]);

        $body = json_encode([
            'event_id' => 'evt_1001',
            'transaction_id' => 'cc_txn_abc123',
            'status' => 'successful',
        ], JSON_THROW_ON_ERROR);

        $validSignature = hash_hmac('sha256', $body, $secret);

        $validRequest = Request::create('/api/v1/webhooks/payments/credit_card', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => $validSignature,
        ], $body);

        $invalidRequest = Request::create('/api/v1/webhooks/payments/credit_card', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => 'invalid_signature',
        ], $body);

        $this->assertTrue($gateway->verifyWebhookSignature($validRequest));
        $this->assertFalse($gateway->verifyWebhookSignature($invalidRequest));
    }
}
