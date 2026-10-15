# Changelog

All notable changes to `payment-gateways` will be documented in this file.

## 2.0.0 - Unreleased

Rewrite around gateway drivers. v1.x is discontinued; see [UPGRADE.md](UPGRADE.md).

- Requires PHP 8.4 and Laravel 12 or 13.
- `PaymentGateways::create()` and `$payment->checkoutUrl()`: one flow and one set of routes for
  every gateway.
- Every gateway result is checked against the payment (reference, amount, currency) before the
  status changes; statuses only move forward.
- Verified notifications for Mercado Pago, PayPal and Getnet; webhook secrets required in production.
- Mercado Pago Checkout Pro driver.
- Webpay on transbank-sdk 5.
- Partial refunds for Webpay, Mercado Pago and PayPal; reversals for Getnet.
- `payment-gateways:sync` command for notifications that never arrived.
- `PaymentStatusChanged` and `PaymentReversed` events; status events dispatched after commit.
- Spanish and English translations; publishable migration.
- `PaymentGateways::fake()` for application tests.
