<?php

namespace Arca\PaymentGateways\Drivers\Flow;

use Arca\PaymentGateways\Contracts\SupportsWebhooks;
use Arca\PaymentGateways\Drivers\AbstractDriver;
use Arca\PaymentGateways\Enums\Currency;
use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\Exceptions\GatewayException;
use Arca\PaymentGateways\Exceptions\PaymentVerificationException;
use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\ValueObjects\ChargeIntent;
use Arca\PaymentGateways\ValueObjects\ChargeResult;
use Arca\PaymentGateways\ValueObjects\Money;
use Arca\PaymentGateways\ValueObjects\WebhookResult;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;

/**
 * Flow (flow.cl). Every request is signed with HMAC-SHA256 of the sorted parameters.
 */
class FlowDriver extends AbstractDriver implements SupportsWebhooks
{
    protected const array STATUS_MAP = [
        1 => PaymentStatus::Pending,
        2 => PaymentStatus::Paid,
        3 => PaymentStatus::Failed,
        4 => PaymentStatus::Failed,
    ];

    public function name(): string
    {
        return 'flow';
    }

    public function charge(Payment $payment, ChargeIntent $intent): ChargeResult
    {
        if (empty($intent->customer->email)) {
            throw new GatewayException(
                message: "Flow needs the buyer email: create payment [{$payment->uuid}] with a Customer that has one.",
                gateway: $this->name(),
            );
        }

        $params = [
            'commerceOrder' => $this->reference($payment),
            'subject' => Str::limit($intent->description, 255),
            'currency' => $intent->money->currency->value,
            'amount' => $intent->money->toDecimalString(),
            'email' => $intent->customer->email,
            'paymentMethod' => (int) ($this->config['payment_method'] ?? 9),
            'urlConfirmation' => $intent->urls->webhookUrl ?? $intent->urls->returnUrl,
            'urlReturn' => $intent->urls->returnUrl,
        ];

        if ($payment->expires_at) {
            $params['timeout'] = max(1, (int) now()->diffInSeconds($payment->expires_at));
        }

        $data = $this->send('POST', 'payment/create', $params, 'Create payment');

        return new ChargeResult(
            status: PaymentStatus::Pending,
            externalId: (string) $data['token'],
            redirectUrl: $data['url'].'?token='.$data['token'],
            raw: Arr::only($data, ['flowOrder']),
        );
    }

    /**
     * Flow posts the buyer back with the payment token; the status is read from the API.
     */
    public function complete(Payment $payment, Request $request): ChargeResult
    {
        $token = (string) $request->input('token', '');

        if ($token !== '' && $token !== $payment->external_id) {
            throw new PaymentVerificationException(
                message: "Flow token does not belong to payment [{$payment->uuid}].",
                gateway: $this->name(),
            );
        }

        return $this->query($payment);
    }

    public function query(Payment $payment): ChargeResult
    {
        return $this->mapStatus(
            $this->send('GET', 'payment/getStatus', ['token' => (string) $payment->external_id], 'Get status'),
        );
    }

    /**
     * Flow's confirmation only carries the token. It is trusted only after reading the payment
     * with a signed API call, so a token is all there is to check here.
     */
    public function verifyWebhook(Request $request): bool
    {
        return (string) $request->input('token', '') !== '';
    }

    public function handleWebhook(Request $request): ?WebhookResult
    {
        $payment = Payment::query()
            ->where('gateway', $this->gateway())
            ->where('external_id', (string) $request->input('token'))
            ->first();

        return $payment ? new WebhookResult($payment, $this->query($payment)) : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function mapStatus(array $data): ChargeResult
    {
        $flowStatus = (int) ($data['status'] ?? 0);
        $status = self::STATUS_MAP[$flowStatus] ?? PaymentStatus::Pending;
        $currency = Currency::tryFrom((string) ($data['currency'] ?? ''));

        if ($currency === null) {
            throw new PaymentVerificationException(
                message: 'Unsupported Flow currency ['.($data['currency'] ?? '').'].',
                gateway: $this->name(),
            );
        }

        return new ChargeResult(
            status: $status,
            transactionId: isset($data['flowOrder']) ? (string) $data['flowOrder'] : null,
            authorizationCode: $data['paymentData']['authorizationCode'] ?? null,
            reference: (string) ($data['commerceOrder'] ?? ''),
            amount: Money::fromDecimal($data['amount'] ?? 0, $currency),
            message: $status === PaymentStatus::Failed ? __("payment-gateways::flow.status.{$flowStatus}") : null,
            raw: Arr::only($data, ['flowOrder', 'commerceOrder', 'requestDate', 'status', 'subject', 'currency', 'amount', 'paymentData']),
        );
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function send(string $method, string $endpoint, array $params, string $action): array
    {
        $this->requireConfig(['api_key', 'secret_key']);

        $params = ['apiKey' => (string) $this->config['api_key']] + $params;
        $params['s'] = $this->sign($params);

        try {
            $response = $method === 'POST'
                ? $this->http()->asForm()->post($endpoint, $params)
                : $this->http(retry: true)->get($endpoint, $params);
        } catch (Throwable $e) {
            throw $this->communicationError($e, $action);
        }

        return $response->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function sign(array $params): string
    {
        ksort($params);

        $toSign = '';
        foreach ($params as $key => $value) {
            $toSign .= $key.$value;
        }

        return hash_hmac('sha256', $toSign, (string) $this->config['secret_key']);
    }

    protected function baseUrl(): string
    {
        return $this->isSandbox()
            ? 'https://sandbox.flow.cl/api/'
            : 'https://www.flow.cl/api/';
    }
}
