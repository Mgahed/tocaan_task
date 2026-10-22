<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Payments\Contracts\PaymentGatewayInterface;
use App\Services\Payments\Gateways\CreditCardGateway;
use App\Services\Payments\Gateways\PaypalGateway;
use App\Services\Payments\PaymentGatewayFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;

class PaymentServiceProvider extends ServiceProvider
{
    /**
     * Register payment gateway strategies and factory in the container.
     */
    public function register(): void
    {
        $this->app->singleton(PaymentGatewayFactory::class, function (Container $app): PaymentGatewayFactory {
            return new PaymentGatewayFactory($app);
        });

        $this->app->bind(CreditCardGateway::class, function (Container $app, array $parameters): CreditCardGateway {
            $config = $parameters['config'] ?? config('payment.gateways.credit_card', []);

            return new CreditCardGateway($config);
        });

        $this->app->bind(PaypalGateway::class, function (Container $app, array $parameters): PaypalGateway {
            $config = $parameters['config'] ?? config('payment.gateways.paypal', []);

            return new PaypalGateway($config);
        });

        // Bind default gateway interface if injected directly
        $this->app->bind(PaymentGatewayInterface::class, function (Container $app): PaymentGatewayInterface {
            /** @var PaymentGatewayFactory $factory */
            $factory = $app->make(PaymentGatewayFactory::class);
            $default = (string) config('payment.default', 'credit_card');

            return $factory->make($default);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
