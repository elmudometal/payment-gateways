# Data reference

Where each piece of payment data is stored, per gateway. Use it to answer certification
questionnaires, reconcile with a gateway panel or trace a payment for support.

Two tables:

- **`payments`**: one row per payment, with the identifiers and the normalized result.
- **`payment_logs`**: every exchange with the gateway, stored **raw** (request and answer exactly
  as sent and received, secrets masked). This is the audit trail: if a gateway adds or renames a
  field, it is still there.

## `payments`

| Column | Meaning | Webpay Plus | Getnet | Flow | Mercado Pago | PayPal | Niubiz |
|---|---|---|---|---|---|---|---|
| `id` | Local id | Sent as **buy order** (`buy_order`) | — | — | — | — | Sent as **purchase number** (`purchaseNumber`) |
| `uuid` | Public id, used in URLs | `session_id` (without dashes) | `reference` (without dashes) | `commerceOrder` | `external_reference` | `reference_id` / `custom_id` | — |
| `gateway` | Configured gateway name (account) | `webpay` or your account name | `getnet` | `flow` | `mercadopago` | `paypal` | `niubiz` |
| `status` | `pending`, `authorized`, `paid`, `failed`, `refunded`, `reversed` | from `status` + `response_code` | from `status.status` | from `status` (1–4) | from `status` | from the capture `status` | from `ACTION_CODE` |
| `amount`, `currency` | Amount in the currency's minor unit | `amount` (CLP) | `payment.amount` | `amount`, `currency` | `transaction_amount`, `currency_id` | `amount.value`, `currency_code` | `order.amount`, `order.currency` |
| `external_id` | The checkout created when the buyer started | `token` (`token_ws`) | `requestId` | `token` | Preference `id` | Order `id` | `sessionKey` |
| `transaction_id` | The actual payment, once paid | — (Webpay refunds by token) | `internalReference` | `flowOrder` | Payment `id` | Capture `id` | `TRANSACTION_ID` |
| `authorization_code` | Issuer authorization | `authorization_code` | `authorization` | `paymentData.authorizationCode` | `authorization_code` | Capture `id` | `AUTHORIZATION_CODE` |
| `failure_message` | Why it was rejected (translated) | from `response_code` | `status.message` | from `status` | from `status_detail` | `status_details.reason` | from `ACTION_CODE` |
| `refunded_amount` | Sum of successful refunds | ✓ | ✓ | — | ✓ | ✓ | — |
| `paid_at` | When the package saw it paid | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `customer` | Buyer data you passed (`name`, `email`, `phone`, `ip`) | | | | | | |
| `receipt` | Normalized receipt details, see below | | | | | | |
| `metadata` | **Your** data. The package never writes it | | | | | | |

### `receipt` keys

| Key | Webpay Plus | Getnet | Flow | Mercado Pago | PayPal | Niubiz |
|---|---|---|---|---|---|---|
| `card_last_four` | `card_detail.card_number` | | | `card.last_four_digits` | | |
| `card_number` (masked) | | | | | | `CARD` |
| `card_brand` | | `franchise` | | | | `BRAND` |
| `payment_type` / `payment_type_code` | `payment_type_code` (VD, VN, VC, SI, S2, NC, VP) and its label | | | `payment_type_id` | | |
| `payment_method` | | `paymentMethodName` | `paymentData.media` | `payment_method_id` | `paypal` | |
| `installments` | `installments_number` | | `paymentData.installments` | `installments` | | `QUOTA_NUMBER` |
| `installment_amount` | `installments_amount` | | | `transaction_details.installment_amount` | | |
| `transaction_date` | `transaction_date` | `status.date` | `paymentData.date` | `date_approved` | Capture `create_time` | `TRANSACTION_DATE` |
| `accounting_date` | `accounting_date` | | | | | |
| `receipt_number` | | `receipt` | | | | |
| `status_detail` | | | | `status_detail` | `status_details.reason` | `ACTION_CODE` |

Read them with `$payment->receipt('installments')`.

## `payment_logs`

| Column | Meaning |
|---|---|
| `payment_id` | The payment. Null for notifications about no known payment (also kept when refused) |
| `gateway` | Configured gateway name |
| `type` | `charge`, `return` (buyer back), `complete`, `query` (sync), `webhook`, `refund` |
| `direction` | `out`: our call to the gateway API. `in`: the gateway (or the buyer's browser) reaching us |
| `method`, `url` | HTTP method and URL (secret query parameters masked) |
| `status_code` | HTTP status of the answer; null when no answer arrived |
| `verified` | For incoming notifications: whether the signature checked out |
| `ip` | For incoming requests: the sender's IP |
| `request` | `out`: the body sent. `in`: `{headers, query, body}` as received (cookies and authorization headers dropped) |
| `response` | The body received, untouched |
| `response_headers` | Answer headers: gateway trace ids such as `Paypal-Debug-Id` or `x-request-id` |
| `error` | Transport error when the call got no answer |
| `created_at` | When it happened |

Masked keys (`payment-gateways.logs.redact`): `apiKey`, `s`, `auth`, `tranKey`, `password`,
`secret`, `secret_key`, `client_secret`, `access_token`, `refresh_token`, `webhook_secret`.
OAuth/token calls (PayPal, Niubiz) are not logged: their answers are credentials.

### What each gateway leaves in the logs

| Gateway | `charge` | `return` (in) | `complete` / `query` | `webhook` | `refund` |
|---|---|---|---|---|---|
| Webpay Plus | `POST …/transactions`: `buy_order`, `session_id`, `amount`, `return_url` → `token`, `url` | `token_ws`, or `TBK_TOKEN` + `TBK_ORDEN_COMPRA` + `TBK_ID_SESION` (aborted, timeout, error) | `PUT …/transactions/{token}` (commit) or `GET` (status): `vci`, `status`, `response_code`, `amount`, `authorization_code`, `payment_type_code`, `installments_*`, `card_detail`, `accounting_date`, `transaction_date` | — | `POST …/refunds`: `type` (`REVERSED`/`NULLIFIED`), `nullified_amount`, `balance` |
| Getnet | `POST api/session` → `requestId`, `processUrl` | as sent by Getnet | `POST api/session/{requestId}`: `status`, `request.payment`, `payment[]` with `internalReference`, `authorization`, `receipt`, `franchise` | in: `requestId`, `status`, `signature`; out: the session | `POST api/reverse` |
| Flow | `POST payment/create` → `token`, `url`, `flowOrder` | `token` (POST) | `GET payment/getStatus`: `flowOrder`, `commerceOrder`, `status`, `amount`, `payer`, `paymentData` | in: `token`; out: `getStatus` | — |
| Mercado Pago | `POST /checkout/preferences`: the preference → `id`, `init_point` | `payment_id`, `status`, `external_reference`, `merchant_order_id`, … | `GET /v1/payments/{id}` or `/search`: the full payment, `payer` included | in: `data.id`, `type`, headers `x-signature`, `x-request-id`; out: the payment | `POST /v1/payments/{id}/refunds` |
| PayPal | `POST /v2/checkout/orders` → order `id`, `links` | `token` (order id), `PayerID` | `GET /v2/checkout/orders/{id}`, `POST …/capture`: order, `purchase_units`, captures, `payer` | in: the event, `paypal-transmission-*` headers; out: `verify-webhook-signature`, order or capture | `POST /v2/payments/captures/{id}/refund` |
| Niubiz | `POST …/token/session/{merchantId}` → `sessionKey` | `transactionToken`, `customerEmail`, `channel` | `POST …/authorization/ecommerce/{merchantId}`: `order` and `dataMap` (`ACTION_CODE`, `ACTION_DESCRIPTION`, `AUTHORIZATION_CODE`, `TRACE_NUMBER`, `CARD`, `BRAND`, `ECI`, …) | — | — |

## Finding things

```php
// Everything that happened with a payment, in order
$payment->logs;

// The raw Webpay commit answer
$payment->logs()->where('type', 'complete')->where('direction', 'out')->latest('id')->first()?->response;

// Notifications refused or about unknown payments
PaymentLog::where('type', 'webhook')->whereNull('payment_id')->get();
```

```sql
-- The full trail of one payment
SELECT type, direction, method, url, status_code, verified, created_at
FROM payment_logs WHERE payment_id = 123 ORDER BY id;
```

## Typical certification questions

| Question | Where |
|---|---|
| Buy order / order number sent | Webpay, Niubiz: `payments.id`. Others: `payments.uuid` (`reference` without dashes for Getnet) |
| Session id (Webpay) | `payments.uuid` without dashes; `charge` log, `request.session_id` |
| Token / checkout id | `payments.external_id` |
| Authorization code | `payments.authorization_code` |
| Transaction / operation id | `payments.transaction_id` |
| Response code (Webpay `response_code`, Niubiz `ACTION_CODE`) | `complete` log `response`; Niubiz also `receipt.status_detail` |
| VCI (Webpay) | Webpay `complete` log, `response.vci` |
| Payment type and installments | `payments.receipt` (`payment_type_code`, `installments`) |
| Last four card digits | `payments.receipt.card_last_four` (Niubiz: `receipt.card_number`, masked) |
| Accounting / transaction date | `payments.receipt` (`accounting_date`, `transaction_date`) |
| How aborted, timed out or failed form returns were handled | `return` log `request.body` / `request.query` (`TBK_*`) and the resulting `payments.status` |
| Notification received and its signature | `webhook` log with `direction = in`: headers, body, `verified` |
| Refund result | `refund` log `response`; `payments.refunded_amount` |
| Trace id for the gateway's support | `response_headers` (`Paypal-Debug-Id`, `x-request-id`) |

Retention: set `PAYMENT_GATEWAYS_LOGS_RETENTION_DAYS` and schedule `model:prune` to delete old
logs. Logs contain buyer data returned by the gateways (email, name, document number): never full
card numbers or security codes, which the gateways do not return.
