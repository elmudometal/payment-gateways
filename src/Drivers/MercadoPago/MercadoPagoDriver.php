<?php

namespace Arca\PaymentGateways\Drivers\MercadoPago;

use Arca\PaymentGateways\Contracts\SupportsRefunds;
use Arca\PaymentGateways\Contracts\SupportsWebhooks;
use Arca\PaymentGateways\Drivers\AbstractDriver;
use Arca\PaymentGateways\Enums\Currency;
use Arca\PaymentGateways\Enums\PaymentStatus;
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
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mercado Pago Checkout Pro: a preference per payment, the buyer pays on Mercado Pago.
 */
class MercadoPagoDriver extends AbstractDriver implements SupportsRefunds, SupportsWebhooks
{
    protected const array STATUS_MAP = [
        'approved' => PaymentStatus::Paid,
        'authorized' => PaymentStatus::Authorized,
        'pending' => PaymentStatus::Pending,
        'in_process' => PaymentStatus::Pending,
        'in_mediation' => PaymentStatus::Pending,
        'rejected' => PaymentStatus::Failed,
        'cancelled' => PaymentStatus::Failed,
        'refunded' => PaymentStatus::Refunded,
        'charged_back' => PaymentStatus::Reversed,
    ];

    /**
     * Fields of the Mercado Pago payment kept as voucher (the full answer carries payer data).
     */
    protected const array VOUCHER_FIELDS = [
        'id', 'status', 'status_detail', 'external_reference', 'transaction_amount', 'currency_id',
        'payment_method_id', 'payment_type_id', 'installments', 'authorization_code',
        'date_created', 'date_approved', 'card.last_four_digits',
    ];

    public function name(): string
    {
        return 'mercadopago';
    }

    public function charge(Payment $payment, ChargeIntent $intent): ChargeResult
    {
        $this->requireConfig(['access_token']);

        $preference = [
            'items' => [[
                'id' => $payment->uuid,
                'title' => Str::limit($intent->description, 250),
                'quantity' => 1,
                'currency_id' => $intent->money->currency->value,
                'unit_price' => $intent->money->toNumber(),
            ]],
            'external_reference' => $this->reference($payment),
            'back_urls' => [
                'success' => $intent->urls->returnUrl,
                'pending' => $intent->urls->returnUrl,
                'failure' => $intent->urls->returnUrl,
            ],
            'metadata' => ['payment_uuid' => $payment->uuid],
        ];

        // Mercado Pago refuses auto_return with a non-public back URL, and cannot deliver
        // notifications to one: both are left out in local development.
        if ($intent->urls->returnIsPublic()) {
            $preference['auto_return'] = 'approved';
        }

        if ($intent->urls->webhookIsReachable()) {
            // source_news=webhooks: signed Webhooks only, no legacy IPN feed.
            $preference['notification_url'] = $intent->urls->webhookUrl.'?source_news=webhooks';
        }

        $payer = array_filter([
            'email' => $intent->customer?->email,
            'name' => $intent->customer?->name,
        ]);

        if ($payer !== []) {
            $preference['payer'] = $payer;
        }

        if (! empty($this->config['statement_descriptor'])) {
            $preference['statement_descriptor'] = $this->config['statement_descriptor'];
        }

        if (! empty($this->config['binary_mode'])) {
            $preference['binary_mode'] = true;
        }

        if ($payment->expires_at) {
            $preference['expires'] = true;
            $preference['expiration_date_to'] = $payment->expires_at->format('Y-m-d\TH:i:s.vP');
        }

        try {
            $data = $this->api()
                ->withHeaders(['X-Idempotency-Key' => 'preference-'.$payment->uuid])
                ->post('/checkout/preferences', $preference)
                ->json();
        } catch (Throwable $e) {
            throw $this->communicationError($e, 'Create preference');
        }

        return new ChargeResult(
            status: PaymentStatus::Pending,
            externalId: (string) $data['id'],
            redirectUrl: (string) $data['init_point'],
            raw: Arr::only($data, ['id', 'init_point', 'date_created']),
        );
    }

    /**
     * The buyer is back from Checkout Pro. The status in the query string is never trusted: the
     * payment is fetched from the API.
     */
    public function complete(Payment $payment, Request $request): ChargeResult
    {
        $paymentId = (string) ($request->query('payment_id') ?? $request->query('collection_id') ?? '');

        if (ctype_digit($paymentId)) {
            return $this->getPayment($paymentId);
        }

        $result = $this->query($payment);

        // Back from Checkout Pro without paying: nothing to wait for.
        if ($result->isPending() && $result->transactionId === null) {
            return new ChargeResult(
                status: PaymentStatus::Failed,
                message: __('payment-gateways::messages.cancelled'),
            );
        }

        return $result;
    }

    /**
     * The most relevant Mercado Pago payment for the preference: an approved one if any, else
     * the latest attempt.
     */
    public function query(Payment $payment): ChargeResult
    {
        try {
            $results = $this->api(retry: true)
                ->get('/v1/payments/search', [
                    'external_reference' => $this->reference($payment),
                    'sort' => 'date_created',
                    'criteria' => 'desc',
                    'limit' => 20,
                ])
                ->json('results') ?? [];
        } catch (Throwable $e) {
            throw $this->communicationError($e, 'Search payments');
        }

        if ($results === []) {
            return new ChargeResult(status: PaymentStatus::Pending);
        }

        $chosen = Arr::first($results, fn (array $p): bool => ($p['status'] ?? null) === 'approved') ?? $results[0];

        return $this->mapPayment($chosen);
    }

    public function getPayment(int|string $paymentId): ChargeResult
    {
        try {
            $data = $this->api(retry: true)->get("/v1/payments/{$paymentId}")->json();
        } catch (Throwable $e) {
            throw $this->communicationError($e, 'Get payment');
        }

        return $this->mapPayment($data);
    }

    public function refund(Payment $payment, ?Money $amount = null): RefundResult
    {
        if ($payment->transaction_id === null) {
            return new RefundResult(
                status: $payment->status,
                message: 'No Mercado Pago payment id stored for this payment.',
            );
        }

        $attempt = count($payment->metadata['refunds'] ?? []);

        try {
            $data = $this->api()
                ->withHeaders(['X-Idempotency-Key' => "refund-{$payment->uuid}-{$attempt}"])
                ->post("/v1/payments/{$payment->transaction_id}/refunds", $amount ? ['amount' => $amount->toNumber()] : [])
                ->json();
        } catch (Throwable $e) {
            throw $this->communicationError($e, 'Refund');
        }

        if (($data['status'] ?? null) !== 'approved') {
            return new RefundResult(
                status: $payment->status,
                refundId: isset($data['id']) ? (string) $data['id'] : null,
                message: 'Refund '.($data['status'] ?? 'failed'),
                raw: $data,
            );
        }

        return new RefundResult(
            status: PaymentStatus::Refunded,
            refundId: (string) $data['id'],
            refundedAmount: isset($data['amount']) ? Money::fromDecimal($data['amount'], $payment->currency) : $amount,
            raw: $data,
        );
    }

    /**
     * Validates the x-signature header (HMAC-SHA256 of id, request id and timestamp).
     *
     * Without webhook_secret, notifications are refused in production and accepted in sandbox:
     * the payment is fetched from the API before anything changes either way.
     */
    public function verifyWebhook(Request $request): bool
    {
        $dataId = $this->notificationDataId($request);

        if ($dataId === '') {
            return true;
        }

        $secret = (string) ($this->config['webhook_secret'] ?? '');

        if ($secret === '') {
            if (! $this->isSandbox()) {
                $this->logError('Notification refused: webhook_secret is not configured.');

                return false;
            }

            $this->logWarning('Notification accepted without signature check: no webhook_secret configured.');

            return true;
        }

        $parts = [];
        foreach (explode(',', (string) $request->header('x-signature')) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$key] = $value;
        }

        if (($parts['ts'] ?? '') === '' || ($parts['v1'] ?? '') === '') {
            return false;
        }

        $id = ctype_alnum($dataId) ? strtolower($dataId) : $dataId;
        $requestId = (string) $request->header('x-request-id');

        $manifest = "id:{$id};".($requestId !== '' ? "request-id:{$requestId};" : '')."ts:{$parts['ts']};";

        return hash_equals(hash_hmac('sha256', $manifest, $secret), $parts['v1'])
            && $this->isFresh($parts['ts']);
    }

    public function handleWebhook(Request $request): ?WebhookResult
    {
        $type = $request->query('type') ?? $request->query('topic') ?? $request->input('type') ?? $request->input('topic');
        $dataId = $this->notificationDataId($request);

        if ($type !== 'payment' || ! ctype_digit($dataId)) {
            return null;
        }

        $result = $this->getPayment($dataId);

        $payment = Payment::query()
            ->where('gateway', $this->name())
            ->where('uuid', (string) $result->reference)
            ->first();

        return $payment ? new WebhookResult($payment, $result) : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function mapPayment(array $data): ChargeResult
    {
        $status = self::STATUS_MAP[$data['status'] ?? ''] ?? PaymentStatus::Pending;
        $currency = Currency::tryFrom((string) ($data['currency_id'] ?? ''));

        if ($currency === null) {
            throw new PaymentVerificationException(
                message: 'Mercado Pago payment '.($data['id'] ?? '?').' has an unsupported currency ['.($data['currency_id'] ?? '').'].',
                gateway: $this->name(),
            );
        }

        $detail = (string) ($data['status_detail'] ?? '');

        return new ChargeResult(
            status: $status,
            transactionId: isset($data['id']) ? (string) $data['id'] : null,
            authorizationCode: isset($data['authorization_code']) ? (string) $data['authorization_code'] : null,
            reference: (string) ($data['external_reference'] ?? ''),
            amount: Money::fromDecimal($data['transaction_amount'] ?? 0, $currency),
            message: $status === PaymentStatus::Failed ? $this->detailMessage($detail) : null,
            raw: Arr::undot(Arr::only(Arr::dot($data), self::VOUCHER_FIELDS)),
        );
    }

    protected function detailMessage(string $detail): string
    {
        $key = "payment-gateways::mercadopago.{$detail}";

        return $detail !== '' && Lang::has($key) ? __($key) : __('payment-gateways::messages.unknown_error');
    }

    /**
     * The notified resource id. PHP turns the "data.id" query parameter into "data_id".
     */
    protected function notificationDataId(Request $request): string
    {
        return (string) ($request->query('data_id') ?? $request->query('id') ?? $request->input('data.id') ?? '');
    }

    /**
     * Optional replay protection: with webhook_tolerance (seconds), older signatures are refused.
     */
    protected function isFresh(string $timestamp): bool
    {
        $tolerance = $this->config['webhook_tolerance'] ?? null;

        if ($tolerance === null || $tolerance === '') {
            return true;
        }

        if (! ctype_digit($timestamp)) {
            return false;
        }

        // Seconds, or milliseconds in some notifications.
        $seconds = strlen($timestamp) > 11 ? intdiv((int) $timestamp, 1000) : (int) $timestamp;

        return abs(now()->getTimestamp() - $seconds) <= (int) $tolerance;
    }

    protected function api(bool $retry = false): PendingRequest
    {
        return $this->http($retry)->withToken((string) $this->config['access_token']);
    }

    protected function baseUrl(): string
    {
        return 'https://api.mercadopago.com';
    }
}
