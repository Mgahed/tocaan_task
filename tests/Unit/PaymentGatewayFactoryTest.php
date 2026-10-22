<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTOs\Payment\PaymentPayloadDTO;
use App\DTOs\Payment\PaymentResultDTO;
use App\DTOs\Payment\WebhookEventDTO;
use App\Enums\PaymentStatusEnum;
use App\Exceptions\UnsupportedPaymentGatewayException;
use App\Models\Order;
use App\Services\Payments\Contracts\PaymentGatewayInterface;
use App\Services\Payments\Gateways\CreditCardGateway;
use App\Services\Payments\Gateways\PaypalGateway;
use App\Services\Payments\PaymentGatewayFactory;
use Illuminate\Http\Request;
use Tests\TestCase;

class PaymentGatewayFactoryTest extends TestCase
{
    public function test_it_resolves_credit_card_and_paypal_gateways(): void
    {
        /** @var PaymentGatewayFactory $factory */
        $factory = $this->app->make(PaymentGatewayFactory::class);

        $creditCard = $factory->make('credit_card');
        $paypal = $factory->make('paypal');

        $this->assertInstanceOf(CreditCardGateway::class, $creditCard);
        $this->assertSame('credit_card', $creditCard->getIdentifier());

        $this->assertInstanceOf(PaypalGateway::class, $paypal);
        $this->assertSame('paypal', $paypal->getIdentifier());
    }

    public function test_it_allows_adding_new_payment_gateway_via_strategy_extension(): void
    {
        /** @var PaymentGatewayFactory $factory */
        $factory = $this->app->make(PaymentGatewayFactory::class);

        $customGateway = new class implements PaymentGatewayInterface {
            public function getIdentifier(): string
            {
                return 'apple_pay';
            }

            public function charge(Order $order, PaymentPayloadDTO $payload): PaymentResultDTO
            {
                return new PaymentResultDTO(
                    status: PaymentStatusEnum::SUCCESSFUL,
                    transactionId: 'ap_txn_12345',
                    gatewayResponse: ['gateway' => 'apple_pay'],
                );
            }

            public function verifyWebhookSignature(Request $request): bool
            {
                return true;
            }

            public function parseWebhookPayload(Request $request): WebhookEventDTO
            {
                return new WebhookEventDTO(
                    gateway: 'apple_pay',
                    eventId: 'evt_1',
                    eventType: 'payment.succeeded',
                    transactionId: 'ap_txn_12345',
                    targetStatus: PaymentStatusEnum::SUCCESSFUL,
                );
            }
        };

        $factory->extend('apple_pay', static fn () => $customGateway);

        $resolved = $factory->make('apple_pay');

        $this->assertSame('apple_pay', $resolved->getIdentifier());
        $this->assertContains('apple_pay', $factory->availableGateways());
    }

    public function test_it_throws_exception_for_unsupported_or_disabled_gateway(): void
    {
        config(['payment.gateways.paypal.enabled' => false]);

        /** @var PaymentGatewayFactory $factory */
        $factory = $this->app->make(PaymentGatewayFactory::class);

        $this->expectException(UnsupportedPaymentGatewayException::class);
        $factory->make('paypal');
    }

    public function test_it_throws_exception_for_unknown_gateway(): void
    {
        /** @var PaymentGatewayFactory $factory */
        $factory = $this->app->make(PaymentGatewayFactory::class);

        $this->expectException(UnsupportedPaymentGatewayException::class);
        $factory->make('unknown_crypto_gateway');
    }
}
