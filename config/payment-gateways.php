<?php

// config for Arca/PaymentGateways
return [
    'getnet' => [
        'commerce_name' => 'Getnet Nombre de Comercio',
        'login' => env('GETNET_LOGIN', '7ffbb7bf1f7361b1200b2e8d74e1d76f'),
        'tranKey' => env('GETNET_TRAN_KEY', 'SnZP3D63n3I9dH9O'),
        'baseUrl' => env('GETNET_BASE_URL', 'https://checkout.test.getnet.cl'),
    ],
    'webpay' => [
        'commerce_name' => 'Webpay Nombre de Comercio',
        'commerce_code' => env('WEBPAY_CODE', ''),
        'commerce_api_key' => env('WEBPAY_API_KEY', ''),
    ],
    'paypal' => [
        'commerce_name' => 'Paypal Nombre de Comercio',
        'base_url' => env('PAYPAL_CLIENT_URL', 'https://api-m.sandbox.paypal.com'),
        'client_id' => env('PAYPAL_CLIENT_ID', ''),
        'client_secret' => env('PAYPAL_CLIENT_SECRET', ''),
    ],
];
