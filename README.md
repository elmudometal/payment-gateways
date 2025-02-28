# payment gateway integration in laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/arca/payment-gateways.svg?style=flat-square)](https://repositorios.arca.cl/payment-gateways/)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/arca/payment-gateways/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/elmudometal/payment-gateways/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/arca/payment-gateways/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/elmudometal/payment-gateways/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/arca/payment-gateways.svg?style=flat-square)](https://repositorios.arca.cl/payment-gateways/)

This package seeks to implement Chilean payment gateways and in general for Laravel allowing easy payment integration.

Payment gateways added:
- Webpay
- Getnet
- PayPal

## Support us
If you want another payment gateway, write without problem and I will try to implement it.

## Installation

You can install the package via composer:

```bash
composer require arca/payment-gateways
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="payment-gateways-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="payment-gateways-config"
```

This is the contents of the published config file:

```php
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
```

You can publish the assets file with:

```bash
php artisan vendor:publish --tag="payment-gateways-assets"
```

Optionally, you can publish the views using

```bash
php artisan vendor:publish --tag="payment-gateways-views"
```

## Events

```bash
 php artisan make:listener YourListenerClass --event=PaymentApproved
 php artisan make:listener Your2ListenerClass --event=PaymentRejected
```

EventServiceProvider
```php
protected $listen = [        
        PaymentApproved::class => [
            YourListenerClass::class,
        ],
        PaymentRejected::class => [
            Your2ListenerClass::class,
        ],
    ];
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Hernan Soto](https://github.com/elmudometal)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
