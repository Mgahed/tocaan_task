<?php

declare(strict_types=1);

use App\Services\Payments\Gateways\CreditCardGateway;
use App\Services\Payments\Gateways\PaypalGateway;

return [
    /*
    |--------------------------------------------------------------------------
    | Default Payment Gateway
    |--------------------------------------------------------------------------
    |
    | This option controls the default payment gateway strategy used when
    | processing payments if none is explicitly specified.
    |
    */
    'default' => env('PAYMENT_DEFAULT_GATEWAY', 'credit_card'),

    /*
    |--------------------------------------------------------------------------
    | Payment Lock Settings
    |--------------------------------------------------------------------------
    |
    | Distributed Redis lock timeout (in seconds) used when processing a
    | payment for an order to prevent concurrent double charges.
    |
    */
    'lock_timeout_seconds' => (int) env('PAYMENT_LOCK_TIMEOUT_SECONDS', 15),

    /*
    |--------------------------------------------------------------------------
    | Configured Payment Gateways (Strategy Pattern Registry)
    |--------------------------------------------------------------------------
    |
    | To add a new payment gateway (e.g. "stripe", "apple_pay", "myfatoorah"):
    | 1. Create a class implementing App\Services\Payments\Contracts\PaymentGatewayInterface
    | 2. Add its configuration entry below with its `driver` class and credentials.
    | No existing service, controller, or model code needs to be modified.
    |
    */
    'gateways' => [
        'credit_card' => [
            'enabled' => (bool) env('CREDIT_CARD_ENABLED', true),
            'driver' => CreditCardGateway::class,
            'api_key' => env('CREDIT_CARD_API_KEY', 'cc_live_sim_key_123456'),
            'api_secret' => env('CREDIT_CARD_API_SECRET', 'cc_live_sim_secret_abcdef'),
            'webhook_secret' => env('CREDIT_CARD_WEBHOOK_SECRET', 'whsec_credit_card_secret_key_999'),
            'endpoint' => env('CREDIT_CARD_ENDPOINT', 'https://api.simulated-cc-gateway.local/v1/charges'),
            'currency' => env('CREDIT_CARD_CURRENCY', 'USD'),
        ],

        'paypal' => [
            'enabled' => (bool) env('PAYPAL_ENABLED', true),
            'driver' => PaypalGateway::class,
            'client_id' => env('PAYPAL_CLIENT_ID', 'paypal_sim_client_id_7890'),
            'client_secret' => env('PAYPAL_CLIENT_SECRET', 'paypal_sim_client_secret_xyz'),
            'webhook_secret' => env('PAYPAL_WEBHOOK_SECRET', 'whsec_paypal_secret_key_888'),
            'mode' => env('PAYPAL_MODE', 'sandbox'),
            'endpoint' => env('PAYPAL_ENDPOINT', 'https://api-m.sandbox.paypal.com'),
            'currency' => env('PAYPAL_CURRENCY', 'USD'),
        ],
    ],
];
