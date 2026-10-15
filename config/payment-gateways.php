<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Payment Gateway
    |--------------------------------------------------------------------------
    */
    'default' => env('PAYMENT_GATEWAY', 'webpay'),

    /*
    |--------------------------------------------------------------------------
    | Payment Gateways
    |--------------------------------------------------------------------------
    */
    'gateways' => [
        'webpay' => [
            'driver' => 'webpay',
            'environment' => env('WEBPAY_ENV', 'sandbox'),
            'commerce_code' => env('WEBPAY_CODE', ''),
            'api_key' => env('WEBPAY_API_KEY', ''),
        ],

        'getnet' => [
            'driver' => 'getnet',
            'environment' => env('GETNET_ENV', 'sandbox'),
            'login' => env('GETNET_LOGIN', ''),
            'tran_key' => env('GETNET_TRAN_KEY', ''),
        ],

        'paypal' => [
            'driver' => 'paypal',
            'environment' => env('PAYPAL_ENV', 'sandbox'),
            'client_id' => env('PAYPAL_CLIENT_ID', ''),
            'client_secret' => env('PAYPAL_CLIENT_SECRET', ''),
            'currency' => env('PAYPAL_CURRENCY', 'USD'),
        ],

        'flow' => [
            'driver' => 'flow',
            'environment' => env('FLOW_ENV', 'sandbox'),
            'api_key' => env('FLOW_API_KEY', ''),
            'secret_key' => env('FLOW_SECRET', ''),
        ],

        'niubiz' => [
            'driver' => 'niubiz',
            'environment' => env('NIUBIZ_ENV', 'sandbox'),
            'merchant_id' => env('NIUBIZ_MERCHANT_ID', ''),
            'user' => env('NIUBIZ_USER', ''),
            'password' => env('NIUBIZ_PASSWORD', ''),
            'currency' => env('NIUBIZ_CURRENCY', 'PEN'),
        ],

        'mercadopago' => [
            'driver' => 'mercadopago',
            'environment' => env('MERCADOPAGO_ENV', 'sandbox'),
            'access_token' => env('MERCADOPAGO_ACCESS_TOKEN', ''),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration
    |--------------------------------------------------------------------------
    */
    'webhook' => [
        'route_prefix' => 'payments/webhooks',
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Configuration
    |--------------------------------------------------------------------------
    */
    'routes' => [
        'prefix' => 'payments',
        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Model
    |--------------------------------------------------------------------------
    */
    'model' => Arca\PaymentGateways\Models\Payment::class,

];
