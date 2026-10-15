<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default gateway
    |--------------------------------------------------------------------------
    */
    'default' => env('PAYMENT_GATEWAY', 'webpay'),

    /*
    |--------------------------------------------------------------------------
    | Gateways
    |--------------------------------------------------------------------------
    |
    | Each key is a gateway name you pass to PaymentGateways::create(). `driver` picks the
    | implementation, so one driver can serve several accounts under different names.
    |
    | `environment` is "sandbox" or "production". Left empty, it follows the application:
    | production when APP_ENV=production, sandbox otherwise.
    |
    */
    'gateways' => [

        'webpay' => [
            'driver' => 'webpay',
            'environment' => env('WEBPAY_ENV'),
            // Empty in sandbox: Transbank's public integration credentials are used.
            'commerce_code' => env('WEBPAY_COMMERCE_CODE'),
            'api_key' => env('WEBPAY_API_KEY'),
        ],

        'getnet' => [
            'driver' => 'getnet',
            'environment' => env('GETNET_ENV'),
            'login' => env('GETNET_LOGIN'),
            'tran_key' => env('GETNET_TRAN_KEY'),
            'locale' => 'es_CL',
            // Minutes the buyer has to complete the checkout.
            'expiration' => 60,
        ],

        'paypal' => [
            'driver' => 'paypal',
            'environment' => env('PAYPAL_ENV'),
            'client_id' => env('PAYPAL_CLIENT_ID'),
            'client_secret' => env('PAYPAL_CLIENT_SECRET'),
            // Id of the webhook registered in the PayPal dashboard, used to verify notifications.
            'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
            'brand_name' => env('PAYPAL_BRAND_NAME', env('APP_NAME')),
        ],

        'flow' => [
            'driver' => 'flow',
            'environment' => env('FLOW_ENV'),
            'api_key' => env('FLOW_API_KEY'),
            'secret_key' => env('FLOW_SECRET_KEY'),
            // 9 = every payment method enabled for the merchant.
            'payment_method' => (int) env('FLOW_PAYMENT_METHOD', 9),
        ],

        'niubiz' => [
            'driver' => 'niubiz',
            'environment' => env('NIUBIZ_ENV'),
            'merchant_id' => env('NIUBIZ_MERCHANT_ID'),
            'user' => env('NIUBIZ_USER'),
            'password' => env('NIUBIZ_PASSWORD'),
            // Optional logo URL shown in the Niubiz payment form.
            'logo' => env('NIUBIZ_LOGO'),
        ],

        'mercadopago' => [
            'driver' => 'mercadopago',
            'environment' => env('MERCADOPAGO_ENV'),
            'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),
            // Secret of the webhook signature (Your integrations > Webhooks). Required in production.
            'webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET'),
            // Seconds a signed notification stays valid (replay protection). null = no limit.
            'webhook_tolerance' => env('MERCADOPAGO_WEBHOOK_TOLERANCE'),
            'statement_descriptor' => env('MERCADOPAGO_STATEMENT_DESCRIPTOR'),
            // true: only approved or rejected, never pending (no cash or bank transfer methods).
            'binary_mode' => (bool) env('MERCADOPAGO_BINARY_MODE', false),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Checkout routes
    |--------------------------------------------------------------------------
    |
    | {prefix}/{payment}            starts the checkout (Payment::checkoutUrl())
    | {prefix}/{payment}/commit     the buyer is back from the gateway (CSRF disabled)
    | {prefix}/{payment}/success, /pending, /failed
    |
    */
    'routes' => [
        'prefix' => 'payments',
        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Result pages
    |--------------------------------------------------------------------------
    |
    | Route names of your own success, pending and failed pages. They receive the payment as
    | route parameter. null uses the package views (publish them to customise).
    |
    */
    'redirects' => [
        'success' => null,
        'pending' => null,
        'failed' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | {prefix}/{gateway} receives the gateway notifications. It must be reachable over public
    | HTTPS: on localhost the package does not send the URL to the gateways.
    |
    */
    'webhook' => [
        'prefix' => 'payments/webhooks',
        'middleware' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP client
    |--------------------------------------------------------------------------
    |
    | Timeouts (seconds) for gateway API calls. Reads are retried on connection errors and 5xx
    | answers; writes never are.
    |
    */
    'http' => [
        'timeout' => 15,
        'connect_timeout' => 5,
        'retries' => 2,
    ],

    'log_channel' => env('PAYMENT_GATEWAYS_LOG_CHANNEL'),

];
