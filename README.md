# Payment Gateways for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/arca/payment-gateways.svg?style=flat-square)](https://packagist.org/packages/arca/payment-gateways)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/elmudometal/payment-gateways/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/elmudometal/payment-gateways/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/arca/payment-gateways.svg?style=flat-square)](https://packagist.org/packages/arca/payment-gateways)

Latin American payment gateways for Laravel behind one API: create a payment, send the buyer to
its checkout URL, and listen to `PaymentApproved`. The package handles the return from the
gateway, the notifications and every status change.

| Gateway      | Countries           | Checkout          | Refunds          | Notifications            |
|--------------|---------------------|-------------------|------------------|--------------------------|
| Webpay Plus  | Chile (CLP)         | Redirect          | Full and partial | —                        |
| Getnet       | Chile               | Redirect          | Full             | Signed (sha1 / sha256)   |
| Flow         | Chile               | Redirect          | —                | Confirmation URL         |
| Mercado Pago | Latin America       | Redirect          | Full and partial | Signed (`x-signature`)   |
| PayPal       | Worldwide           | Redirect          | Full and partial | Verified with PayPal API |
| Niubiz       | Peru (PEN, USD)     | Embedded form     | —                | —                        |

## How it protects payments

- **The gateway API is the source of truth.** Return URLs and notifications only tell the package
  to ask the gateway; query strings are never trusted.
- **Every result is checked against the payment**: the reference (order number) must match, and an
  approval for a lower amount or another currency is refused.
- **Statuses only move forward.** Out-of-order notifications never turn a paid payment into a
  rejected one, and a payment never goes back to pending.
- **Notifications are verified** (signatures, PayPal's verification API) and a missing secret is a
  refusal in production.
- **One place changes a payment**: `PaymentService` locks the row, applies the result and
  dispatches the events after the transaction commits.

## Requirements

- PHP 8.4+
- Laravel 12 or 13

## Installation

```bash
composer require arca/payment-gateways
```

Install the SDK of the gateways that need one:

```bash
composer require transbank/transbank-sdk   # Webpay (^5.0)
composer require dnetix/redirection        # Getnet (^2.1)
```

Publish the config and the migration, then migrate:

```bash
php artisan vendor:publish --tag="payment-gateways-config"
php artisan vendor:publish --tag="payment-gateways-migrations"
php artisan migrate
```

## Configuration

Each gateway has an `environment` (`sandbox` or `production`). Leave it empty to follow the
application: production when `APP_ENV=production`, sandbox otherwise.

```env
PAYMENT_GATEWAY=webpay

# Webpay: empty in sandbox, Transbank's integration credentials are used
WEBPAY_COMMERCE_CODE=
WEBPAY_API_KEY=

# Getnet
GETNET_LOGIN=
GETNET_TRAN_KEY=

# Flow
FLOW_API_KEY=
FLOW_SECRET_KEY=

# Mercado Pago
MERCADOPAGO_ACCESS_TOKEN=
MERCADOPAGO_WEBHOOK_SECRET=        # required in production
MERCADOPAGO_WEBHOOK_TOLERANCE=     # optional, seconds (replay protection)
MERCADOPAGO_STATEMENT_DESCRIPTOR=

# PayPal
PAYPAL_CLIENT_ID=
PAYPAL_CLIENT_SECRET=
PAYPAL_WEBHOOK_ID=                 # required in production

# Niubiz
NIUBIZ_MERCHANT_ID=
NIUBIZ_USER=
NIUBIZ_PASSWORD=
```

### Several accounts of one gateway

The gateway name is a config key and `driver` picks the implementation, so one driver can serve
several accounts, for example one Webpay commerce for courses and another for workshops:

```php
'gateways' => [
    'webpay_courses' => [
        'driver' => 'webpay',
        'commerce_code' => env('WEBPAY_COURSES_COMMERCE_CODE'),
        'api_key' => env('WEBPAY_COURSES_API_KEY'),
    ],
    'webpay_workshops' => [
        'driver' => 'webpay',
        'commerce_code' => env('WEBPAY_WORKSHOPS_COMMERCE_CODE'),
        'api_key' => env('WEBPAY_WORKSHOPS_API_KEY'),
    ],
],
```

```php
$payment = PaymentGateways::create('webpay_courses', Money::CLP(50000), 'Laravel course', payable: $enrollment);
```

Each payment remembers its account (`$payment->gateway === 'webpay_courses'`), so the return,
`sync` and refunds use that account's credentials, and notifications arrive at
`/payments/webhooks/webpay_courses`. Tell them apart in listeners by `$payment->gateway` or by
the payable model.

## Usage

### Taking a payment

```php
use Arca\PaymentGateways\Facades\PaymentGateways;
use Arca\PaymentGateways\ValueObjects\Customer;
use Arca\PaymentGateways\ValueObjects\Money;

$payment = PaymentGateways::create(
    gateway: 'webpay',
    money: Money::CLP(15000),
    description: "Order #{$order->id}",
    payable: $order,                                          // optional, morph relation
    customer: new Customer(name: $user->name, email: $user->email),
);

return redirect($payment->checkoutUrl());
```

`checkoutUrl()` starts the checkout: it redirects the buyer to the gateway (or renders the Niubiz
form). When the buyer comes back, the package confirms the payment with the gateway and shows
the success, pending or failed page.

Then fulfil the order when it is paid, whether the buyer came back or only a notification arrived:

```php
use Arca\PaymentGateways\Events\PaymentApproved;

Event::listen(PaymentApproved::class, function (PaymentApproved $event) {
    $event->payment->payable->markAsPaid();
});
```

### Routes

| Route                         | URL                              | Purpose                                      |
|-------------------------------|----------------------------------|----------------------------------------------|
| `payments.show`               | `/payments/{payment}`            | Starts the checkout (`checkoutUrl()`)        |
| `payments.commit`             | `/payments/{payment}/commit`     | The buyer is back (GET or POST, no CSRF)     |
| `payments.success` / `pending` / `failed` | `/payments/{payment}/…` | Result pages                                 |
| `payments.webhook`            | `/payments/webhooks/{gateway}`   | Gateway notifications                        |

Send the buyer to your own pages instead of the package views:

```php
// config/payment-gateways.php
'redirects' => [
    'success' => 'orders.thanks',   // route names, they receive the payment
    'pending' => 'orders.pending',
    'failed' => 'orders.failed',
],
```

Or publish and edit the views (`--tag="payment-gateways-views"`) and translations
(`--tag="payment-gateways-translations"`, Spanish and English included).

### Notifications

The webhook URL is sent to the gateway automatically when it is public HTTPS (not on localhost):

- **Mercado Pago**: set the webhook secret from *Your integrations > Webhooks* in
  `MERCADOPAGO_WEBHOOK_SECRET`.
- **PayPal**: register `https://your-app/payments/webhooks/paypal` in the developer dashboard
  (events `CHECKOUT.ORDER.APPROVED` and `PAYMENT.CAPTURE.*`) and set `PAYPAL_WEBHOOK_ID`.
- **Getnet**: configure `https://your-app/payments/webhooks/getnet` as notification URL of the site.
- **Flow**: nothing to do, the confirmation URL goes with each payment.

Schedule the sync command to catch notifications that never arrived:

```php
Schedule::command('payment-gateways:sync')->everyFifteenMinutes();
```

It asks the gateways for the status of pending payments started at least 10 minutes ago
(`--older-than`, `--max-age`, `--gateway`, or a single payment uuid).

### Status, money and refunds

```php
$payment->status;              // PaymentStatus::Pending, Authorized, Paid, Failed, Refunded, Reversed
$payment->isPaid();
$payment->money()->format();   // "15.000 CLP"
$payment->failureMessage();    // why the gateway rejected it
$payment->receipt('installments');  // receipt details: card, installments, payment type...
$payment->metadata;            // your own data, never written by the package

PaymentGateways::sync($payment);                       // ask the gateway now
PaymentGateways::refund($payment);                     // full refund
PaymentGateways::refund($payment, Money::CLP(5000));   // partial refund
```

`Money` holds amounts in the currency's minor unit: `Money::CLP(15000)` is 15.000 pesos,
`Money::USD(1999)` is 19.99 dollars, and `Money::fromDecimal('19.99', 'USD')` builds the same.

### Audit log

Every exchange with a gateway is stored raw in `payment_logs`: API calls (request and answer as
sent and received, secrets masked), the buyer coming back, and every notification, including
refused ones and those about unknown payments.

```php
$payment->logs;   // the full trail, oldest first
```

Keep them as long as you need: set `PAYMENT_GATEWAYS_LOGS_RETENTION_DAYS` and schedule
`Schedule::command('model:prune')->daily()` to delete older ones.

**[DATA.md](DATA.md)** maps every column and log to the fields of each gateway: where to find the
buy order, authorization code, VCI, card digits or a notification when a certification asks.

### Events

| Event                  | When                                               |
|------------------------|----------------------------------------------------|
| `PaymentInitiated`     | The checkout started                               |
| `PaymentStatusChanged` | Any status change (with `from` and `to`)           |
| `PaymentAuthorized`    | Authorized, not captured yet                       |
| `PaymentApproved`      | Paid                                               |
| `PaymentRejected`      | Rejected or cancelled                              |
| `PaymentRefunded`      | Fully refunded                                     |
| `PaymentReversed`      | Chargeback or reversal                             |
| `WebhookReceived`      | A notification passed verification                 |

Status events are dispatched after the database transaction commits.

## Custom gateways

```bash
php artisan make:payment-driver Khipu
```

The driver lands in `app/PaymentDrivers/Khipu`. Register it and add its config:

```php
PaymentGateways::extend('khipu', fn ($app, array $config) => new KhipuDriver($config));
```

A driver implements `charge()`, `complete()` and `query()`; add `SupportsRefunds` and
`SupportsWebhooks` when the gateway offers them. Return the reference and amount the gateway
reports in each `ChargeResult`: the package checks them before changing the payment.

## Testing your application

```php
use Arca\PaymentGateways\Facades\PaymentGateways;

$fake = PaymentGateways::fake('webpay');

$this->get($payment->checkoutUrl());                   // redirects to a fake checkout
$this->get(route('payments.commit', $payment));        // the fake approves it

$fake->assertCharged()->assertCompleted();

// Other answers
$fake->completeWith(new ChargeResult(status: PaymentStatus::Failed, message: 'Card declined'));
$fake->respondRefundWith(new RefundResult(status: PaymentStatus::Paid));
$fake->rejectWebhooks();
```

Run the package tests with `composer test`.

## Upgrading from v1.x

v2 is a clean break: v1.x is discontinued and v2 ships no compatibility layer. See
[UPGRADE.md](UPGRADE.md).

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Security

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Hernan Soto](https://github.com/elmudometal)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
