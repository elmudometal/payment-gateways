# Upgrading from v1.x to v2.0

v2.0 is a rewrite around gateway drivers. **v1.x is discontinued** and v2 ships no compatibility
layer: there are no legacy routes, controller adapters or automatic database migration. Plan the
upgrade as a change to your application, not as a drop-in package update.

## Requirements

- **PHP 8.4+** (was 8.1+)
- **Laravel 12 or 13** (was 9+)

## Step 1: Update composer.json

```bash
composer require arca/payment-gateways:^2.0
```

Gateway SDKs are now optional. Install only what you use:

```bash
# If you use Webpay:
composer require transbank/transbank-sdk

# If you use Getnet:
composer require dnetix/redirection
```

## Step 2: Republish the config

The config format has completely changed:

```bash
php artisan vendor:publish --tag="payment-gateways-config" --force
```

```php
// v1
'webpay' => [
    'commerce_name' => '...',
    'commerce_code' => env('WEBPAY_CODE'),
    'commerce_api_key' => env('WEBPAY_API_KEY'),
    'controller' => WebpayController::class,
],

// v2
'default' => env('PAYMENT_GATEWAY', 'webpay'),
'gateways' => [
    'webpay' => [
        'driver' => 'webpay',
        'environment' => env('WEBPAY_ENV', 'sandbox'),
        'commerce_code' => env('WEBPAY_CODE'),
        'api_key' => env('WEBPAY_API_KEY'),
    ],
],
```

- A top-level `default` key sets the default gateway.
- All gateways are nested under `gateways`.
- The `controller` key is gone: customise behaviour with events or `PaymentGateways::extend()`.
- An explicit `environment` key replaces detection through `app()->environment()`.
- `commerce_name` is gone.

## Step 3: Migrate the payments table

v2 uses a new `payments` schema. Write a migration in your application that moves your data:

| v1 column  | v2 column     | Notes                                                                     |
|------------|---------------|---------------------------------------------------------------------------|
| `type`     | `gateway`     | Lowercase gateway name (`webpay`, `flow`, ...)                            |
| `voucher`  | `metadata`    | JSON; v2 stores the raw gateway response under `metadata.voucher`         |
| `comments` | `description` |                                                                           |
| `model_*`  | `payable_*`   | Morph columns renamed                                                     |
| `status`   | `status`      | `Paid`→`paid`, `Pending`→`pending`, `Canceled`→`failed`, `Reversed`→`reversed` |
| `amount`   | `amount`      | `bigInteger`, in the currency's minor unit                                |
| `token`    | `external_id` | Indexed                                                                   |
| —          | `currency`    | New, ISO 4217 code (`CLP` for existing Chilean payments)                  |
| —          | `paid_at`     | New; backfill from `updated_at` for paid records if you need it           |
| —          | `expires_at`  | New                                                                       |

## Step 4: Update model references

```php
// v1
$payment->status === Payment::PAID_STATUS;
$payment->model();
$payment->voucher;

// v2
use Arca\PaymentGateways\Enums\PaymentStatus;

$payment->status === PaymentStatus::Paid; // or $payment->isPaid()
$payment->payable();
$payment->metadata['voucher'];
```

## Step 5: Replace custom controllers

v1 let you override a gateway controller through config and its `after*` hooks. In v2, listen to
events instead:

```php
Event::listen(PaymentApproved::class, function (PaymentApproved $event) {
    $payment = $event->payment;
});
```

Or replace the driver:

```php
PaymentGateways::extend('webpay', fn ($app, $config) => new MyWebpayDriver($config));
```

Event payloads are readonly promoted properties: `$event->payment` still works, assigning to it does not.

## Step 6: Update routes and views

v1 had per-gateway routes (`webpay.init`, `getnet.commit`, ...). v2 uses one set of routes:

| v1 route            | v2 route           |
|---------------------|--------------------|
| `{gateway}.init`    | `payments.show`    |
| `{gateway}.commit`  | `payments.commit`  |
| `{gateway}.successful` | `payments.success` |
| `{gateway}.rejected`   | `payments.failed`  |
| —                   | `payments.webhook` |

Update every link and every URL registered with a gateway (return and notification URLs).

v1 per-gateway views are replaced by `payment-gateways::checkout`, `payment-gateways::success` and
`payment-gateways::failed`. Publish them to customise:

```bash
php artisan vendor:publish --tag="payment-gateways-views" --force
```
