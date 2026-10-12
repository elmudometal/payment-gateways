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
- Flow
- Niubiz
- Mercado Pago (Checkout Pro)

## Support us
If you want another payment gateway, write without problem and I will try to implement it.

## Installation

You can install the package via composer:

```bash
composer require arca/payment-gateways
```

Webpay and Getnet use their own SDKs, which are optional so applications only install what they use:

```bash
composer require transbank/transbank-sdk:^3.0   # Webpay
composer require dnetix/redirection:^2.1        # Getnet
```

Mercado Pago, Flow, PayPal and Niubiz need no extra package.

The migration is not run automatically. Publish it and run it with:

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
    'niubiz' => [
        'commerce_name' => 'Niubiz Nombre de Comercio',
        'merchant_id' => env('NIUBIZ_MERCHANT_ID', ''),
        'user' => env('NIUBIZ_USER', ''),
        'password' => env('NIUBIZ_PASSWORD', ''),
        'controller' => \Arca\PaymentGateways\Http\Controllers\NiubizController::class,
    ],
    'mercadopago' => [
        'commerce_name' => 'Mercado Pago Nombre de Comercio',
        'access_token' => env('MERCADOPAGO_ACCESS_TOKEN', ''),
        'webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET', ''),
        // Seconds a signed notification stays valid (replay protection). null = no limit.
        'webhook_tolerance' => env('MERCADOPAGO_WEBHOOK_TOLERANCE'),
        'currency' => env('MERCADOPAGO_CURRENCY', 'CLP'),
        'statement_descriptor' => env('MERCADOPAGO_STATEMENT_DESCRIPTOR', ''),
        'controller' => \Arca\PaymentGateways\Http\Controllers\MercadoPagoController::class,
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

## Mercado Pago (Checkout Pro)

Set your credentials in `.env`:

```bash
MERCADOPAGO_ACCESS_TOKEN=APP_USR-...
MERCADOPAGO_WEBHOOK_SECRET=      # "Clave secreta" from Your integrations > Webhooks (required in production)
MERCADOPAGO_WEBHOOK_TOLERANCE=   # optional: seconds a signature stays valid (e.g. 600)
MERCADOPAGO_CURRENCY=CLP         # CLP, ARS, BRL, MXN, COP, PEN, UYU
```

Redirect the buyer to `route('mercadopago.init', $payment->uuid)`. The package creates a preference, sends the buyer to Mercado Pago and, on return (`mercadopago.commit`), queries the payment through the API (the query string status is never trusted). Each preference sets its own `notification_url` (`POST /mercadopago/notification/{uuid}`), so the payment status is also updated asynchronously, including payments that end up `pending` (cash, transfers).

Status mapping: `approved` → `Paid`, `rejected`/`cancelled` → `Canceled`, `refunded`/`charged_back` → `Reversed`, anything else → `Pending`. An approved payment for less than `amount` is not marked as paid.

Good to know:

- **Routes.** The pages the buyer visits (`init`, `commit`, `successful`, `pending`, `rejected`) run with the `web` middleware, so your `after*` hooks can use the session and the signed-in user. The notification route does not, so Mercado Pago's POST is never blocked by CSRF.
- **Local development.** `auto_return` and `notification_url` are only sent when the URL is public HTTPS. On `localhost`, `*.test` or plain HTTP, Mercado Pago would reject the preference, so the buyer returns with the button on Mercado Pago's page and no webhook is expected; use a tunnel (ngrok, Herd share) to test webhooks.
- **Webhook signature.** With `MERCADOPAGO_WEBHOOK_SECRET` set, every notification must carry a valid `x-signature`. Without it, notifications are refused in production and accepted (with a log warning) elsewhere.
- **Resilience.** API calls time out after 15 seconds. Reading a payment is retried on connection errors and 5xx answers; creating a preference is never retried.

To send the buyer back to your own pages, extend the controller, override the hooks and point `payment-gateways.mercadopago.controller` to it:

```php
class MercadoPagoController extends \Arca\PaymentGateways\Http\Controllers\MercadoPagoController
{
    public function afterSuccessful(Payment $payment)
    {
        return redirect()->route('orders.show', $payment->model)->with('status', '¡Pago aprobado!');
    }
}
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

`PaymentApproved` fires when a payment becomes `Paid` and `PaymentRejected` when it becomes `Canceled`, once the save has completed. Listeners may safely update the same payment (for example to add a comment); that does not fire the event again.

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
