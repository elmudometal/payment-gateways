# Payment Gateways for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/arca/payment-gateways.svg?style=flat-square)](https://packagist.org/packages/arca/payment-gateways)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/elmudometal/payment-gateways/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/elmudometal/payment-gateways/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/arca/payment-gateways.svg?style=flat-square)](https://packagist.org/packages/arca/payment-gateways)

A clean, testable, and extensible payment gateway integration for Laravel. Add new payment providers with zero modifications to the package.

## Supported Gateways

| Gateway     | Charge | Refund | Webhooks |
|-------------|--------|--------|----------|
| Webpay      | Yes    | —      | Yes      |
| Getnet      | Yes    | —      | Yes      |
| PayPal      | Yes    | Yes    | Yes      |
| Flow        | Yes    | —      | Yes      |
| Niubiz      | Yes    | —      | —        |
| MercadoPago | Yes    | Yes    | Yes      |

## Requirements

- PHP 8.4+
- Laravel 12 or 13

## Installation

```bash
composer require arca/payment-gateways
```

Install optional SDK dependencies only for the gateways you use:

```bash
# Webpay
composer require transbank/transbank-sdk

# Getnet
composer require dnetix/redirection
```

Publish the config and run migrations:

```bash
php artisan vendor:publish --tag="payment-gateways-config"
php artisan migrate
```

## Configuration

Add your gateway credentials to `.env`:

```env
PAYMENT_GATEWAY=webpay

# Webpay
WEBPAY_ENV=sandbox
WEBPAY_CODE=your-commerce-code
WEBPAY_API_KEY=your-api-key

# PayPal
PAYPAL_ENV=sandbox
PAYPAL_CLIENT_ID=your-client-id
PAYPAL_CLIENT_SECRET=your-secret

# MercadoPago
MERCADOPAGO_ENV=sandbox
MERCADOPAGO_ACCESS_TOKEN=your-access-token

# Flow
FLOW_ENV=sandbox
FLOW_API_KEY=your-api-key
FLOW_SECRET=your-secret-key

# Getnet
GETNET_ENV=sandbox
GETNET_LOGIN=your-login
GETNET_TRAN_KEY=your-tran-key

# Niubiz
NIUBIZ_ENV=sandbox
NIUBIZ_MERCHANT_ID=your-merchant-id
NIUBIZ_USER=your-user
NIUBIZ_PASSWORD=your-password
```

## Usage

### Creating and charging a payment

```php
use Arca\PaymentGateways\Services\PaymentService;
use Arca\PaymentGateways\ValueObjects\ChargeIntent;
use Arca\PaymentGateways\ValueObjects\Customer;
use Arca\PaymentGateways\ValueObjects\Money;
use Arca\PaymentGateways\ValueObjects\ReturnUrls;

$service = app(PaymentService::class);

// 1. Create the payment record
$intent = new ChargeIntent(
    money: Money::CLP(50000),
    description: 'Order #123',
    urls: new ReturnUrls(
        returnUrl: route('payments.commit', ':uuid:'),
        webhookUrl: route('payments.webhook', 'webpay'),
    ),
    customer: new Customer(email: 'buyer@example.com'),
);

$payment = $service->create('webpay', $intent, $order); // $order = your payable model

// 2. Charge — most gateways return a redirect URL
$result = $service->charge($payment, $intent);

if ($result->requiresRedirect()) {
    return redirect()->away($result->redirectUrl);
}
```

### Using the Facade

```php
use Arca\PaymentGateways\Facades\PaymentGateways;

// Use the default gateway
$driver = PaymentGateways::driver();

// Use a specific gateway
$driver = PaymentGateways::driver('mercadopago');
```

### Money value object

```php
use Arca\PaymentGateways\ValueObjects\Money;
use Arca\PaymentGateways\Enums\Currency;

Money::CLP(50000);                          // 50.000 CLP (no decimals)
Money::USD(1999);                           // $19.99 USD (in cents)
Money::PEN(3500);                           // S/ 35.00 PEN (in cents)
Money::fromDecimal(19.99, Currency::USD);   // $19.99 USD
```

### Querying payment status

```php
$result = $service->query($payment);

if ($result->isApproved()) {
    // Payment was successful
}
```

### Refunds

```php
// Full refund (PayPal, MercadoPago)
$result = $service->refund($payment);

// Partial refund
$result = $service->refund($payment, Money::USD(500));
```

### Associating with your models

The `Payment` model uses a polymorphic `payable` relationship:

```php
$payment = $service->create('webpay', $intent, $order);

// Later
$payment->payable; // returns your Order model
```

## Events

All events are dispatched automatically by `PaymentService`:

| Event              | When                           |
|--------------------|--------------------------------|
| `PaymentInitiated` | `charge()` is called           |
| `PaymentApproved`  | Status changes to `paid`       |
| `PaymentRejected`  | Status changes to `failed`     |
| `PaymentRefunded`  | Refund is successful           |
| `WebhookReceived`  | Any webhook hits the endpoint  |

```php
use Arca\PaymentGateways\Events\PaymentApproved;

Event::listen(PaymentApproved::class, function (PaymentApproved $event) {
    $payment = $event->payment;
    // Fulfill the order, send email, etc.
});
```

## Webhooks

A unified webhook endpoint is registered automatically:

```
POST /payments/webhooks/{gateway}
```

Each gateway driver verifies the webhook signature and updates the payment. The `WebhookReceived` event is dispatched for every valid webhook.

## Extending with custom gateways

### Using the command

```bash
php artisan make:payment-driver MercadoLibre
```

This scaffolds `app/PaymentDrivers/MercadoLibre/MercadoLibreDriver.php`.

### Registering the driver

```php
use Arca\PaymentGateways\Facades\PaymentGateways;

// In a service provider
PaymentGateways::extend('mercadolibre', function ($app) {
    $config = config('payment-gateways.gateways.mercadolibre');
    return new \App\PaymentDrivers\MercadoLibre\MercadoLibreDriver($config);
});
```

Add the config:

```php
// config/payment-gateways.php
'gateways' => [
    'mercadolibre' => [
        'driver' => 'mercadolibre',
        'environment' => env('MERCADOLIBRE_ENV', 'sandbox'),
        // your credentials...
    ],
],
```

### Optional interfaces

Drivers can implement additional capabilities:

```php
use Arca\PaymentGateways\Contracts\SupportsRefunds;
use Arca\PaymentGateways\Contracts\SupportsWebhooks;

class MyDriver extends AbstractDriver implements PaymentGateway, SupportsRefunds, SupportsWebhooks
{
    // ...
}
```

## Testing

The package provides a fake driver for testing:

```php
use Arca\PaymentGateways\Facades\PaymentGateways;
use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\ValueObjects\ChargeResult;

// Fake the default gateway
$fake = PaymentGateways::fake();

// Run your code...

$fake->assertCharged(1);
$fake->assertNotCharged();
$fake->assertRefunded(1);

// Simulate failures
$fake->respondWith(new ChargeResult(
    status: PaymentStatus::Failed,
    message: 'Card declined',
));

// Webhooks sent to the fake are accepted unless you reject them
$fake->rejectWebhooks();
$fake->assertWebhookHandled(0);
```

Run the package tests:

```bash
composer test
```

## Upgrading from v1.x

v2 is a clean break: v1.x is discontinued and v2 ships no compatibility layer. See [UPGRADE.md](UPGRADE.md) for what changed and how to move an existing application.

## Changelog

Please see [CHANGELOG.md](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Hernan Soto](https://github.com/elmudometal)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
