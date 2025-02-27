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
    'flow' => [
        'commerce_name' => 'Flow Nombre de Comercio',
        'api_key' => env('FLOW_API_KEY'),
        'secret_key' => env('FLOW_SECRET'),
        'sandbox_url' => 'https://sandbox.flow.cl/api',
        'production_url' => 'https://www.flow.cl/api',
        'controller' => \Arca\PaymentGateways\Http\Controllers\FlowController::class,
        'status' => [
            '1' => 'pendiente de pago',
            '2' => 'pagada',
            '3' => 'rechazada',
            '4' => 'anulada',
            '-1' => 'Tarjeta inválida',
            '-11' => 'Excede límite de reintentos de rechazos',
            '-2' => 'Error de conexión',
            '-3' => 'Excede monto máximo',
            '-4' => 'Fecha de expiración inválida',
            '-5' => 'Problema en autenticación',
            '-6' => 'Rechazo general',
            '-7' => 'Tarjeta bloqueada',
            '-8' => 'Tarjeta vencida',
            '-9' => 'Transacción no soportada',
            '-10' => 'Problema en la transacción',
            '999' => 'Error desconocido',
        ],
    ],
];
