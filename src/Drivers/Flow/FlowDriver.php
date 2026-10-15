<?php

namespace Arca\PaymentGateways\Drivers\Flow;

use Arca\PaymentGateways\Contracts\PaymentGateway;
use Arca\PaymentGateways\Contracts\SupportsWebhooks;
use Arca\PaymentGateways\Drivers\AbstractDriver;
use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\Exceptions\GatewayCommunicationException;
use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\ValueObjects\ChargeIntent;
use Arca\PaymentGateways\ValueObjects\ChargeResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class FlowDriver extends AbstractDriver implements PaymentGateway, SupportsWebhooks
{
    protected const array STATUS_MAP = [
        1 => PaymentStatus::Pending,
        2 => PaymentStatus::Paid,
        3 => PaymentStatus::Failed,
        4 => PaymentStatus::Reversed,
    ];

    public function name(): string
    {
        return 'flow';
    }

    public function charge(Payment $payment, ChargeIntent $intent): ChargeResult
    {
        try {
            $params = [
                'commerceOrder' => $payment->uuid,
                'subject' => $intent->description,
                'currency' => $intent->money->currency->value,
                'amount' => $intent->money->toDecimal(),
                'email' => $intent->customer?->email ?? 'no-reply@example.com',
                'paymentMethod' => 9,
                'urlConfirmation' => $intent->urls->webhookUrl ?? $intent->urls->returnUrl,
                'urlReturn' => $intent->urls->returnUrl,
            ];

            $response = $this->send('payment/create', $params, 'POST');

            $payment->external_id = $response['token'];
            $payment->save();

            return new ChargeResult(
                status: PaymentStatus::Pending,
                externalId: $response['token'],
                redirectUrl: $response['url'].'?token='.$response['token'],
            );
        } catch (\Throwable $e) {
            $this->logError('Charge failed', ['error' => $e->getMessage()]);

            throw new GatewayCommunicationException(
                message: $e->getMessage(),
                gateway: $this->name(),
                previous: $e,
            );
        }
    }

    public function query(Payment $payment): ChargeResult
    {
        try {
            $response = $this->send('payment/getStatus', ['token' => $payment->external_id]);
            $status = self::STATUS_MAP[(int) ($response['status'] ?? 0)] ?? PaymentStatus::Failed;

            return new ChargeResult(
                status: $status,
                externalId: $payment->external_id,
                authorizationCode: $response['authorizationCode'] ?? null,
                raw: $response,
            );
        } catch (\Throwable $e) {
            $this->logError('Query failed', ['error' => $e->getMessage()]);

            throw new GatewayCommunicationException(
                message: $e->getMessage(),
                gateway: $this->name(),
                previous: $e,
            );
        }
    }

    public function verifyWebhook(Request $request): bool
    {
        return $request->has('token');
    }

    public function handleWebhook(Request $request): void
    {
        $token = $request->input('token');
        $payment = Payment::where('external_id', $token)->first();

        if (! $payment) {
            return;
        }

        $response = $this->send('payment/getStatus', ['token' => $token]);
        $status = self::STATUS_MAP[(int) ($response['status'] ?? 0)] ?? PaymentStatus::Failed;

        $payment->status = $status;
        $payment->metadata = array_merge($payment->metadata ?? [], ['voucher' => $response]);

        if ($status === PaymentStatus::Paid && ! $payment->paid_at) {
            $payment->paid_at = now();
        }

        $payment->save();
    }

    protected function baseUrl(): string
    {
        return $this->isSandbox()
            ? 'https://sandbox.flow.cl/api'
            : 'https://www.flow.cl/api';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function send(string $endpoint, array $params, string $method = 'GET'): array
    {
        $url = $this->baseUrl().'/'.$endpoint;
        $params = array_merge(['apiKey' => $this->config['api_key']], $params);
        $params['s'] = $this->sign($params);

        $response = ($method === 'POST')
            ? Http::asForm()->post($url, $params)
            : Http::get($url, $params);

        if (! $response->successful()) {
            throw new GatewayCommunicationException(
                message: 'Flow API error: '.$response->body(),
                gateway: $this->name(),
                context: ['status' => $response->status()],
            );
        }

        return $response->json();
    }

    protected function sign(array $params): string
    {
        ksort($params);
        $toSign = implode('', array_map(
            fn ($k, $v) => $k.$v,
            array_keys($params),
            $params,
        ));

        return hash_hmac('sha256', $toSign, $this->config['secret_key']);
    }
}
