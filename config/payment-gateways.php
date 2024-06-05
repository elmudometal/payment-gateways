<?php

// config for Arca/PaymentGateways
return [
    'getnet' => [
        'commerce_name' => 'Getnet Nombre de Comercio',
        'login' => env('GETNET_LOGIN', ''),
        'tranKey' => env('GETNET_TRAN_KEY', ''),
        'controller' => \Arca\PaymentGateways\Http\Controllers\GetnetController::class,
    ],
    'webpay' => [
        'commerce_name' => 'Webpay Nombre de Comercio',
        'commerce_code' => env('WEBPAY_CODE', ''),
        'commerce_api_key' => env('WEBPAY_API_KEY', ''),
        'controller' => \Arca\PaymentGateways\Http\Controllers\WebpayController::class,
    ],
    'paypal' => [
        'commerce_name' => 'Paypal Nombre de Comercio',
        'client_id' => env('PAYPAL_CLIENT_ID', ''),
        'client_secret' => env('PAYPAL_CLIENT_SECRET', ''),
        'controller' => \Arca\PaymentGateways\Http\Controllers\PaypalController::class,
    ],
];
