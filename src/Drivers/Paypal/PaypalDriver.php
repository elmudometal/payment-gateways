<?php

namespace Arca\PaymentGateways\Drivers\Paypal;

use Arca\PaymentGateways\Contracts\SupportsRefunds;
use Arca\PaymentGateways\Contracts\SupportsWebhooks;
use Arca\PaymentGateways\Drivers\AbstractDriver;
use Arca\PaymentGateways\Enums\Currency;
use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\Exceptions\GatewayCommunicationException;
use Arca\PaymentGateways\Exceptions\PaymentVerificationException;
use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\ValueObjects\ChargeIntent;
use Arca\PaymentGateways\ValueObjects\ChargeResult;
use Arca\PaymentGateways\ValueObjects\Money;
use Arca\PaymentGateways\ValueObjects\RefundResult;
use Arca\PaymentGateways\ValueObjects\WebhookResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * PayPal Orders v2: the buyer approves the order on PayPal, it is captured on return.
 */
class PaypalDriver extends AbstractDriver implements SupportsRefunds, SupportsWebhooks
{
    /**
     * Webhook events that can change a payment.
     */
    protected const array WEBHOOK_EVENTS = [
        'CHECKOUT.ORDER.APPROVED',
        'PAYMENT.CAPTURE.COMPLETED',
        'PAYMENT.CAPTURE.DENIED',
        'PAYMENT.CAPTURE.PENDING',
        'PAYMENT.CAPTURE.REFUNDED',
        'PAYMENT.CAPTURE.REVERSED',
    ];

    public function name(): string
    {
        return 'paypal';
    }

    public function charge(Payment $payment, ChargeIntent $intent): ChargeResult
    {
        try {
            $data = $this->api()
                ->withHeaders(['PayPal-Request-Id' => 'order-'.$payment->uuid])
                ->post('/v2/checkout/orders', [
                    'intent' => 'CAPTURE',
                    'purchase_units' => [[
                        'reference_id' => $this->reference($payment),
                        'custom_id' => $this->reference($payment),
                        'description' => Str::limit($intent->description, 127),
                        'amount' => [
                            'currency_code' => $intent->money->currency->value,
                            'value' => $intent->money->toDecimalString(),
                        ],
                    ]],
                    'payment_source' => [
                        'paypal' => [
                            'experience_context' => array_filter([
                                'brand_name' => $this->config['brand_name'] ?? null,
                                'user_action' => 'PAY_NOW',
                                'shipping_preference' => 'NO_SHIPPING',
                                'return_url' => $intent->urls->returnUrl,
                                'cancel_url' => $intent->urls->cancelUrl ?? $intent->urls->returnUrl,
                            ]),
                        ],
                    ],
                ])
                ->json();
        } catch (Throwable $e) {
            throw $this->communicationError($e, 'Create order');
        }

        $approve = Arr::first($data['links'] ?? [], fn (array $link): bool => in_array($link['rel'] ?? null, ['payer-action', 'approve'], true));

        if (! isset($approve['href'])) {
            throw new GatewayCommunicationException(
                message: 'PayPal order has no approval link.',
                gateway: $this->name(),
                context: ['order' => $data['id'] ?? null, 'status' => $data['status'] ?? null],
            );
        }

        return new ChargeResult(
            status: PaymentStatus::Pending,
            externalId: (string) $data['id'],
            redirectUrl: (string) $approve['href'],
            raw: Arr::only($data, ['id', 'status']),
        );
    }

    /**
     * PayPal sends the buyer back with ?token={order id}. An approved order is captured here;
     * the capture result is what decides the payment.
     */
    public function complete(Payment $payment, Request $request): ChargeResult
    {
        $token = (string) $request->query('token', '');

        if ($token !== '' && $token !== $payment->external_id) {
            throw new PaymentVerificationException(
                message: "PayPal order [{$token}] does not belong to payment [{$payment->uuid}].",
                gateway: $this->name(),
            );
        }

        $order = $this->getOrder((string) $payment->external_id);

        if (($order['status'] ?? null) === 'APPROVED') {
            return $this->capture($payment);
        }

        if ($request->boolean('cancelled') && in_array($order['status'] ?? null, ['CREATED', 'PAYER_ACTION_REQUIRED'], true)) {
            return new ChargeResult(
                status: PaymentStatus::Failed,
                message: __('payment-gateways::messages.cancelled'),
            );
        }

        return $this->mapOrder($order);
    }

    public function query(Payment $payment): ChargeResult
    {
        return $this->mapOrder($this->getOrder((string) $payment->external_id));
    }

    public function capture(Payment $payment): ChargeResult
    {
        try {
            $order = $this->api()
                ->withHeaders(['PayPal-Request-Id' => 'capture-'.$payment->uuid])
                ->withBody('{}', 'application/json')
                ->post("/v2/checkout/orders/{$payment->external_id}/capture")
                ->json();
        } catch (GatewayCommunicationException $e) {
            // 422 ORDER_ALREADY_CAPTURED and friends: the order tells the real state.
            if (($e->context['status'] ?? null) === 422) {
                return $this->query($payment);
            }

            throw $this->communicationError($e, 'Capture order');
        } catch (Throwable $e) {
            throw $this->communicationError($e, 'Capture order');
        }

        return $this->mapOrder($order);
    }

    public function refund(Payment $payment, ?Money $amount = null): RefundResult
    {
        if ($payment->transaction_id === null) {
            return new RefundResult(status: $payment->status, message: 'No PayPal capture id stored for this payment.');
        }

        $attempt = count($payment->metadata['refunds'] ?? []);

        $body = $amount ? [
            'amount' => [
                'value' => $amount->toDecimalString(),
                'currency_code' => $amount->currency->value,
            ],
        ] : [];

        try {
            $data = $this->api()
                ->withHeaders(['PayPal-Request-Id' => "refund-{$payment->uuid}-{$attempt}"])
                ->withBody($body === [] ? '{}' : (string) json_encode($body), 'application/json')
                ->post("/v2/payments/captures/{$payment->transaction_id}/refund")
                ->json();
        } catch (Throwable $e) {
            throw $this->communicationError($e, 'Refund');
        }

        $completed = ($data['status'] ?? null) === 'COMPLETED';

        return new RefundResult(
            status: $completed ? PaymentStatus::Refunded : $payment->status,
            refundId: $data['id'] ?? null,
            refundedAmount: $completed ? ($amount ?? $payment->money()) : null,
            message: $completed ? null : 'Refund '.($data['status'] ?? 'failed'),
            raw: $data,
        );
    }

    /**
     * Verified with PayPal's verify-webhook-signature API against the configured webhook_id.
     * Without webhook_id, notifications are refused in production and accepted in sandbox (the
     * order is read from the API before anything changes either way).
     */
    public function verifyWebhook(Request $request): bool
    {
        $webhookId = (string) ($this->config['webhook_id'] ?? '');

        if ($webhookId === '') {
            if (! $this->isSandbox()) {
                $this->logError('Notification refused: webhook_id is not configured.');

                return false;
            }

            $this->logWarning('Notification accepted without signature check: no webhook_id configured.');

            return true;
        }

        $headers = [
            'auth_algo' => 'paypal-auth-algo',
            'cert_url' => 'paypal-cert-url',
            'transmission_id' => 'paypal-transmission-id',
            'transmission_sig' => 'paypal-transmission-sig',
            'transmission_time' => 'paypal-transmission-time',
        ];

        $payload = [];
        foreach ($headers as $field => $header) {
            if (! $request->hasHeader($header)) {
                return false;
            }

            $payload[$field] = $request->header($header);
        }

        $payload['webhook_id'] = $webhookId;

        // The event goes back byte for byte: decoding and encoding it again can change it and
        // break the signature.
        $event = $request->getContent();

        if (! json_validate($event)) {
            return false;
        }

        $body = substr((string) json_encode($payload), 0, -1).',"webhook_event":'.$event.'}';

        try {
            $status = $this->api()
                ->withBody($body, 'application/json')
                ->post('/v1/notifications/verify-webhook-signature')
                ->json('verification_status');
        } catch (Throwable $e) {
            throw $this->communicationError($e, 'Verify webhook');
        }

        return $status === 'SUCCESS';
    }

    public function handleWebhook(Request $request): ?WebhookResult
    {
        if (! in_array($request->input('event_type'), self::WEBHOOK_EVENTS, true)) {
            return null;
        }

        $orderId = $request->input('event_type') === 'CHECKOUT.ORDER.APPROVED'
            ? $request->input('resource.id')
            : $request->input('resource.supplementary_data.related_ids.order_id');

        if (! is_string($orderId) || $orderId === '') {
            return null;
        }

        $payment = Payment::query()
            ->where('gateway', $this->name())
            ->where('external_id', $orderId)
            ->first();

        if ($payment === null) {
            return null;
        }

        // The buyer approved but never came back: capture from here.
        $result = $request->input('event_type') === 'CHECKOUT.ORDER.APPROVED' && $payment->isPending()
            ? $this->capture($payment)
            : $this->query($payment);

        return new WebhookResult($payment, $result);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOrder(string $orderId): array
    {
        try {
            return $this->api(retry: true)->get("/v2/checkout/orders/{$orderId}")->json();
        } catch (Throwable $e) {
            throw $this->communicationError($e, 'Get order');
        }
    }

    /**
     * An order's status comes from its first capture once captured.
     *
     * @param  array<string, mixed>  $order
     */
    protected function mapOrder(array $order): ChargeResult
    {
        $unit = $order['purchase_units'][0] ?? [];
        $capture = $unit['payments']['captures'][0] ?? null;

        $status = match (true) {
            $capture !== null => match ($capture['status'] ?? null) {
                'COMPLETED', 'PARTIALLY_REFUNDED' => PaymentStatus::Paid,
                'REFUNDED' => PaymentStatus::Refunded,
                'DECLINED', 'FAILED' => PaymentStatus::Failed,
                default => PaymentStatus::Pending,
            },
            ($order['status'] ?? null) === 'VOIDED' => PaymentStatus::Failed,
            default => PaymentStatus::Pending,
        };

        $money = $capture['amount'] ?? $unit['amount'] ?? null;
        $amount = null;

        if (is_array($money)) {
            $currency = Currency::tryFrom((string) ($money['currency_code'] ?? ''))
                ?? throw new PaymentVerificationException(
                    message: 'Unsupported PayPal currency ['.($money['currency_code'] ?? '').'].',
                    gateway: $this->name(),
                );

            $amount = Money::fromDecimal((string) $money['value'], $currency);
        }

        return new ChargeResult(
            status: $status,
            externalId: isset($order['id']) ? (string) $order['id'] : null,
            transactionId: $capture['id'] ?? null,
            authorizationCode: $capture['id'] ?? null,
            reference: $unit['reference_id'] ?? null,
            amount: $amount,
            message: $status === PaymentStatus::Failed
                ? ($capture['status_details']['reason'] ?? __('payment-gateways::messages.unknown_error'))
                : null,
            raw: [
                'order_id' => $order['id'] ?? null,
                'order_status' => $order['status'] ?? null,
                'capture' => $capture ? Arr::only($capture, ['id', 'status', 'amount', 'create_time', 'status_details']) : null,
                'payer_id' => $order['payer']['payer_id'] ?? null,
            ],
        );
    }

    protected function api(bool $retry = false): PendingRequest
    {
        return $this->http($retry)->withToken($this->accessToken());
    }

    /**
     * OAuth token, cached until shortly before it expires.
     */
    protected function accessToken(): string
    {
        $this->requireConfig(['client_id', 'client_secret']);

        $key = 'payment-gateways:paypal:token:'.md5($this->baseUrl().$this->config['client_id']);

        $cached = Cache::get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $data = $this->http(retry: true)
                ->asForm()
                ->withBasicAuth((string) $this->config['client_id'], (string) $this->config['client_secret'])
                ->post('/v1/oauth2/token', ['grant_type' => 'client_credentials'])
                ->json();
        } catch (Throwable $e) {
            throw $this->communicationError($e, 'Get access token');
        }

        Cache::put($key, $data['access_token'], max(60, (int) ($data['expires_in'] ?? 3600) - 300));

        return (string) $data['access_token'];
    }

    protected function baseUrl(): string
    {
        return $this->isSandbox()
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
    }
}
