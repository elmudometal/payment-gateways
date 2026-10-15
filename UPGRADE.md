# Upgrading from v1.x to v2.0

v2.0 is a rewrite around gateway drivers. **v1.x is discontinued** and v2 ships no compatibility
layer: there are no legacy routes, controller adapters or automatic database migration. Plan the
upgrade as a change to your application, not as a drop-in package update.

## Requirements

- **PHP 8.4+** (was 8.1+)
- **Laravel 12 or 13** (was 9+)
- **transbank/transbank-sdk ^5.0** if you use Webpay (was ^3.0)

## 1. Update the package

```bash
composer require arca/payment-gateways:^2.0
composer require transbank/transbank-sdk:^5.0   # Webpay
composer require dnetix/redirection:^2.1        # Getnet
```

## 2. Republish the config

```bash
php artisan vendor:publish --tag="payment-gateways-config" --force
```

Gateways now live under `gateways`, with a `driver` and an optional `environment`. The
`controller` and `commerce_name` keys are gone. Some environment variables were renamed:

| v1                   | v2                      |
|----------------------|-------------------------|
| `WEBPAY_CODE`        | `WEBPAY_COMMERCE_CODE`  |
| `FLOW_SECRET`        | `FLOW_SECRET_KEY`       |
| `MERCADOPAGO_CURRENCY` | — (the payment has its own currency) |
| —                    | `PAYPAL_WEBHOOK_ID`     |

`environment` follows `APP_ENV` when empty. In v1 the sandbox Getnet credentials were hardcoded:
set `GETNET_LOGIN` and `GETNET_TRAN_KEY` for every environment now.

## 3. Migrate the payments table

Publish the v2 migrations with
`php artisan vendor:publish --tag="payment-gateways-migrations"` to see the new schema (`payments`
and the `payment_logs` audit table), then write a migration in your application that moves your data:

| v1 column  | v2 column        | Notes                                                                          |
|------------|------------------|--------------------------------------------------------------------------------|
| `type`     | `gateway`        | Lowercase gateway name (`webpay`, `flow`, ...)                                 |
| `voucher`  | `payment_logs`   | v2 stores every raw gateway exchange in `payment_logs`; copy the v1 voucher there (`type` = `query`, `response` = the JSON) to keep it |
| `comments` | `description`    |                                                                                |
| `model_*`  | `payable_*`      | Morph columns renamed                                                          |
| `status`   | `status`         | `Paid`→`paid`, `Pending`→`pending`, `Canceled`→`failed`, `Reversed`→`reversed` |
| `amount`   | `amount`         | Unsigned big integer, in the currency's minor unit (cents for USD and PEN)     |
| `token`    | `external_id`    | Webpay token, Mercado Pago preference, PayPal order, ...                       |
| —          | `transaction_id` | The gateway's payment id, used for refunds                                     |
| —          | `currency`       | ISO 4217 code (`CLP` for v1 Chilean payments, `PEN` for Niubiz)                |
| —          | `paid_at`        | Backfill from `updated_at` for paid records if you need it                     |
| —          | `expires_at`     |                                                                                |

v1 stored PayPal and Niubiz amounts as whole units: multiply them by 100.

## 4. Start payments the v2 way

```php
// v1
$payment = Payment::create(['type' => 'Webpay', 'amount' => 15000, 'comments' => 'Order #1', ...]);
return redirect()->route('webpay.init', $payment->uuid);

// v2
$payment = PaymentGateways::create('webpay', Money::CLP(15000), 'Order #1', payable: $order);
return redirect($payment->checkoutUrl());
```

## 5. Update model references

```php
// v1
$payment->status === Payment::PAID_STATUS;
$payment->model;
$payment->voucher;

// v2
$payment->status === PaymentStatus::Paid; // or $payment->isPaid()
$payment->payable;
$payment->logs;          // raw gateway answers
$payment->receipt('installments');
```

## 6. Replace custom controllers

v1 let you override a gateway controller and its `after*` hooks. In v2:

- react to payments with events (`PaymentApproved`, `PaymentRejected`, ...), which fire for the
  buyer's return and for notifications alike;
- show your own pages with the `redirects` config (route names for success, pending and failed),
  or publish the package views;
- replace a driver with `PaymentGateways::extend()`.

## 7. Update routes and gateway settings

| v1 route                 | v2 route           |
|--------------------------|--------------------|
| `{gateway}.init`         | `payments.show`    |
| `{gateway}.commit`       | `payments.commit`  |
| `{gateway}.successful`   | `payments.success` |
| —                        | `payments.pending` |
| `{gateway}.rejected`     | `payments.failed`  |
| `{gateway}.notification` | `payments.webhook` (`/payments/webhooks/{gateway}`) |

Update the URLs registered in the gateway panels (Getnet notification URL, PayPal webhook).
