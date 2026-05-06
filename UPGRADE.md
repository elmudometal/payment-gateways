# Upgrading from v1.x to v2.0

This guide covers the breaking changes introduced in v2.0 and how to migrate your application.

## Requirements

- **PHP 8.4+** (was 8.1+)
- **Laravel 13.0+** (was 9.0+)

## Step 1: Update composer.json

```bash
composer require arca/payment-gateways:^2.0
```

SDK dependencies are now optional. Install only what you use:

```bash
# If you use Webpay:
composer require transbank/transbank-sdk:^3.0

# If you use Getnet:
composer require dnetix/redirection:^2.1
```

## Step 2: Republish the config

The config format has completely changed. Republish it:

```bash
php artisan vendor:publish --tag="payment-gateways-config" --force
```

### v1 config format (old)
```php
'webpay' => [
    'commerce_name' => '...',
    'commerce_code' => env('WEBPAY_CODE'),
    'commerce_api_key' => env('WEBPAY_API_KEY'),
    'controller' => WebpayController::class,
],
```

### v2 config format (new)
```php
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

Key changes:
- Top-level `default` key to set the default gateway
- All gateways nested under `gateways` key
- `controller` key removed (use `PaymentGateways::extend()` for customization)
- Explicit `environment` key replaces auto-detection via `app()->environment()`
- `commerce_name` removed (not used by APIs)

## Step 3: Run the automatic migration

v2 includes an artisan command that handles the full migration automatically:

```bash
# Preview what will change (no modifications)
php artisan payment-gateways:migrate-from-v1 --dry-run

# Run the migration (creates a backup table first)
php artisan payment-gateways:migrate-from-v1

# Run without backup
php artisan payment-gateways:migrate-from-v1 --no-backup
```

The command handles:

| v1 Column   | v2 Column          | Notes                          |
|-------------|--------------------|---------------------------------|
| `type`      | `gateway`          | Renamed + normalized to lowercase |
| `voucher`   | `metadata`         | Renamed, still JSON             |
| `comments`  | `description`      | Renamed                         |
| `model_*`   | `payable_*`        | Renamed morph columns           |
| `status`    | `status`           | `Paid`→`paid`, `Pending`→`pending`, `Canceled`→`failed`, `Reversed`→`reversed` |
| `amount`    | `amount`           | Now `bigInteger` (was `integer`)|
| `token`     | `external_id`      | Renamed + indexed               |
| —           | `currency`         | New column (default: `CLP`)     |
| —           | `paid_at`          | Backfilled from `updated_at` for paid records |
| —           | `expires_at`       | New timestamp                   |

A backup table `payments_v1_backup` is created automatically unless `--no-backup` is passed.

## Step 4: Update model references

### Status constants → Enum

```php
// v1 (old)
use Arca\PaymentGateways\Models\Payment;
$payment->status === Payment::PAID_STATUS;

// v2 (new)
use Arca\PaymentGateways\Enums\PaymentStatus;
$payment->status === PaymentStatus::Paid;
// or
$payment->isPaid();
```

### Morph relationship renamed

```php
// v1
$payment->model()

// v2
$payment->payable()
```

### Voucher → Metadata

```php
// v1
$payment->voucher

// v2
$payment->metadata
$payment->metadata['voucher'] // raw gateway response stored here
```

## Step 5: Update custom controllers

v1 allowed overriding controllers via config. v2 uses the driver pattern instead.

### v1 approach (old)
```php
// config
'webpay' => ['controller' => App\Http\Controllers\MyWebpayController::class]

// controller
class MyWebpayController extends WebpayController {
    public function afterSuccessful(Payment $payment) { ... }
}
```

### v2 approach (new)

Listen to events instead:

```php
// In EventServiceProvider or via Event::listen()
Event::listen(PaymentApproved::class, function (PaymentApproved $event) {
    // Your custom logic
    $payment = $event->payment;
});
```

Or extend the driver:

```php
PaymentGateways::extend('webpay', function ($app, $config) {
    return new MyCustomWebpayDriver($config);
});
```

## Step 6: Update event listeners

Events now use promoted readonly constructor properties:

```php
// v1
class PaymentApproved {
    public Payment $payment; // public property
}

// v2
class PaymentApproved {
    public function __construct(
        public readonly Payment $payment, // readonly promoted property
    ) {}
}
```

Your listeners should still work — `$event->payment` is the same. But if you were setting `$event->payment = ...`, that will now fail.

## Step 7: Update routes

v1 had per-gateway routes (`webpay.init`, `getnet.commit`, etc.). v2 uses unified routes:

| v1 Route               | v2 Route               |
|-------------------------|------------------------|
| `webpay.init`           | `payments.show`        |
| `webpay.commit`         | `payments.commit`      |
| `webpay.successful`     | `payments.success`     |
| `webpay.rejected`       | `payments.failed`      |
| `flow.init`             | `payments.show`        |
| (no webhook route)      | `payments.webhook`     |

## Step 8: Update views (optional)

v1 had per-gateway views (`payment-gateways::webpay.successful`). v2 has three shared views:

- `payment-gateways::checkout`
- `payment-gateways::success`
- `payment-gateways::failed`

Publish and customize them:

```bash
php artisan vendor:publish --tag="payment-gateways-views" --force
```

## Compatibility helpers (optional)

v2 ships with two optional helpers to ease the transition:

### Legacy route redirects

If your frontend or external systems still link to v1 route names (`webpay.init`, `flow.commit`, etc.), register redirects in your `AppServiceProvider`:

```php
use Arca\PaymentGateways\Support\Legacy\LegacyRouteRedirector;

public function boot(): void
{
    LegacyRouteRedirector::register();
}
```

This registers 301/307 redirects from all v1 routes to their v2 equivalents. Remove once all clients are updated.

### Legacy controller adapter

If you had custom controllers extending v1 gateway controllers (e.g. `MyWebpayController extends WebpayController`), you can use `LegacyControllerAdapter` as a bridge:

```php
use Arca\PaymentGateways\Support\Legacy\LegacyControllerAdapter;

class MyWebpayController extends LegacyControllerAdapter
{
    protected string $gateway = 'webpay';

    public function afterSuccessful(Payment $payment)
    {
        // Your custom success logic
        return view('my-success', compact('payment'));
    }

    public function afterRejected(Payment $payment, string $error = '')
    {
        return view('my-error', compact('payment', 'error'));
    }
}
```

Register your own routes pointing to this controller. Under the hood, it delegates to the v2 driver system. This is a transitional tool — migrate to events or driver extension when possible.

## New features in v2

- **`PaymentGateways::extend()`** — Add custom gateways without forking
- **`PaymentGateways::fake()`** — Test payment flows without hitting real APIs
- **`PaymentService`** — Orchestration layer with automatic event dispatching
- **Value Objects** — `Money`, `ChargeIntent`, `ChargeResult`, `RefundResult`
- **Webhook support** — Unified `POST /payments/webhooks/{gateway}` endpoint
- **Refunds** — PayPal and MercadoPago support refunds via `SupportsRefunds` interface
- **MercadoPago** — New driver for Checkout Pro API
- **`make:payment-driver`** — Scaffold new drivers with one command
- **`payment-gateways:migrate-from-v1`** — Automatic database migration from v1
