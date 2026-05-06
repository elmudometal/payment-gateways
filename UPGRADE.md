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

## Step 3: Run the migration

The `payments` table schema has changed:

| v1 Column   | v2 Column          | Notes                          |
|-------------|--------------------|---------------------------------|
| `type`      | `gateway`          | Renamed                         |
| `voucher`   | `metadata`         | Renamed, still JSON             |
| `comments`  | `description`      | Renamed                         |
| `model_*`   | `payable_*`        | Renamed morph columns           |
| `status`    | `status`           | Now uses `PaymentStatus` enum values (`pending`, `paid`, `failed`) |
| `amount`    | `amount`           | Now `bigInteger` (was `integer`)|
| —           | `currency`         | New column (default: `CLP`)     |
| —           | `external_id`      | New column (replaces `token`)   |
| —           | `paid_at`          | New timestamp                   |
| —           | `expires_at`       | New timestamp                   |
| `token`     | —                  | Removed (use `external_id`)     |

Create a migration to update existing data:

```php
Schema::table('payments', function (Blueprint $table) {
    $table->renameColumn('type', 'gateway');
    $table->renameColumn('voucher', 'metadata');
    $table->renameColumn('comments', 'description');
    $table->renameColumn('model_id', 'payable_id');
    $table->renameColumn('model_type', 'payable_type');
    $table->renameColumn('token', 'external_id');
    $table->string('currency', 3)->default('CLP')->after('amount');
    $table->timestamp('paid_at')->nullable()->after('metadata');
    $table->timestamp('expires_at')->nullable()->after('paid_at');
});

// Update status values
DB::table('payments')->where('status', 'Paid')->update(['status' => 'paid']);
DB::table('payments')->where('status', 'Pending')->update(['status' => 'pending']);
DB::table('payments')->where('status', 'Canceled')->update(['status' => 'failed']);
DB::table('payments')->where('status', 'Reversed')->update(['status' => 'reversed']);

// Backfill paid_at for existing paid payments
DB::table('payments')->where('status', 'paid')->whereNull('paid_at')
    ->update(['paid_at' => DB::raw('updated_at')]);
```

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

## New features in v2

- **`PaymentGateways::extend()`** — Add custom gateways without forking
- **`PaymentGateways::fake()`** — Test payment flows without hitting real APIs
- **`PaymentService`** — Orchestration layer with automatic event dispatching
- **Value Objects** — `Money`, `ChargeIntent`, `ChargeResult`, `RefundResult`
- **Webhook support** — Unified `POST /payments/webhooks/{gateway}` endpoint
- **Refunds** — PayPal supports refunds via `SupportsRefunds` interface
- **`make:payment-driver`** — Scaffold new drivers with one command
