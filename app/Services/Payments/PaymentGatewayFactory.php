<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Exceptions\UnsupportedPaymentGatewayException;
use App\Services\Payments\Contracts\PaymentGatewayInterface;
use Closure;
use Illuminate\Contracts\Container\Container;

class PaymentGatewayFactory
{
    /**
     * Custom registered gateway creators (Strategy extensions).
     *
     * @var array<string, Closure>
     */
    private array $customCreators = [];

    public function __construct(
        private readonly Container $container
    ) {}

    /**
     * Register a custom payment gateway strategy resolver at runtime.
     */
    public function extend(string $gateway, Closure $callback): self
    {
        $this->customCreators[strtolower(trim($gateway))] = $callback;

        return $this;
    }

    /**
     * Resolve a PaymentGatewayInterface implementation by gateway identifier.
     *
     * @throws UnsupportedPaymentGatewayException
     */
    public function make(string $gateway): PaymentGatewayInterface
    {
        $normalized = strtolower(trim($gateway));

        if (isset($this->customCreators[$normalized])) {
            $instance = ($this->customCreators[$normalized])($this->container);

            if (! $instance instanceof PaymentGatewayInterface) {
                throw new UnsupportedPaymentGatewayException($normalized);
            }

            return $instance;
        }

        /** @var array<string, mixed>|null $gatewayConfig */
        $gatewayConfig = config("payment.gateways.{$normalized}");

        if (! is_array($gatewayConfig) || empty($gatewayConfig['enabled']) || empty($gatewayConfig['driver'])) {
            throw new UnsupportedPaymentGatewayException($normalized);
        }

        /** @var class-string<PaymentGatewayInterface> $driverClass */
        $driverClass = $gatewayConfig['driver'];

        if (! class_exists($driverClass)) {
            throw new UnsupportedPaymentGatewayException($normalized);
        }

        $instance = $this->container->make($driverClass, ['config' => $gatewayConfig]);

        if (! $instance instanceof PaymentGatewayInterface) {
            throw new UnsupportedPaymentGatewayException($normalized);
        }

        return $instance;
    }

    /**
     * Return a list of all currently enabled payment gateway identifiers.
     *
     * @return array<int, string>
     */
    public function availableGateways(): array
    {
        /** @var array<string, array<string, mixed>> $configured */
        $configured = config('payment.gateways', []);

        $enabled = [];
        foreach ($configured as $key => $settings) {
            if (! empty($settings['enabled'])) {
                $enabled[] = (string) $key;
            }
        }

        return array_values(array_unique(array_merge($enabled, array_keys($this->customCreators))));
    }
}
