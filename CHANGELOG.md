# Changelog

All notable changes to `payment-gateways` will be documented in this file.

## 2.0.1 - 2026-10-15

Schema change to the 2.0.0 migrations (no application used 2.0.0 yet): republish them.

- New `payment_logs` audit table: every exchange with a gateway stored raw (API calls with
  request, answer and trace headers; the buyer coming back; every notification, including refused
  ones). Secrets are masked, OAuth calls are not logged, and old logs can be pruned with
  `model:prune` (`PAYMENT_GATEWAYS_LOGS_RETENTION_DAYS`).
- `metadata` now belongs to the application only. The package data moved to dedicated columns:
  `customer`, `receipt` (normalized card, installments, payment type and dates for receipts),
  `failure_message` and `refunded_amount`. `Payment::voucher()` is gone: use `$payment->logs` and
  `$payment->receipt()`.
- The failure message is cleared once a rejected payment gets paid.
- The success page shows the receipt details Transbank certification asks for.
- [DATA.md](DATA.md): where every gateway field is stored, for certifications and audits.
- Mercado Pago and PayPal refund idempotency keys are derived from the refunded amount.
- The release workflow opens a pull request with the CHANGELOG update instead of pushing to `main`.

## 2.0.0 - 2026-10-15

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
