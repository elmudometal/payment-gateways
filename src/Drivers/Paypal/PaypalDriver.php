<?php

namespace Arca\PaymentGateways\Drivers\Paypal;

use Arca\PaymentGateways\Contracts\PaymentGateway;
use Arca\PaymentGateways\Contracts\SupportsRefunds;
use Arca\PaymentGateways\Contracts\SupportsWebhooks;
use Arca\PaymentGateways\Drivers\AbstractDriver;
use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\Exceptions\GatewayCommunicationException;
use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\ValueObjects\ChargeIntent;
use Arca\PaymentGateways\ValueObjects\ChargeResult;
use Arca\PaymentGateways\ValueObjects\Money;
use Arca\PaymentGateways\ValueObjects\RefundResult;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class PaypalDriver extends AbstractDriver implements PaymentGateway, SupportsRefunds, SupportsWebhooks
{
    public function name(): string
    {
        return 'paypal';
    }

    public function charge(Payment $payment, ChargeIntent $intent): ChargeResult
    {
        try {
            $accessToken = $this->getAccessToken();
            $currency = $this->config['currency'] ?? $intent->money->currency->value;

            $response = Http::acceptJson()
                ->withToken($accessToken)
                ->post($this->baseUrl().'/v2/checkout/orders', [
                    'intent' => 'CAPTURE',
                    'purchase_units' => [
                        [
                            'reference_id' => $payment->uuid,
                            'description' => $intent->description,
                            'amount' => [
                                'currency_code' => $currency,
                                'value' => number_format($intent->money->toDecimal(), 2, '.', ''),
                            ],
                        ],
                    ],
                    'application_context' => [
                        'return_url' => $intent->urls->returnUrl,
                        'cancel_url' => $intent->urls->cancelUrl ?? $intent->urls->returnUrl,
                    ],
                ]);

            if (! $response->successful()) {
                return new ChargeResult(
                    status: PaymentStatus::Failed,
                    message: 'Failed to create PayPal order',
                    raw: $response->json(),
                );
            }

            $data = $response->json();
            $orderId = $data['id'];
            $approveLink = collect($data['links'] ?? [])->firstWhere('rel', 'approve');

            $payment->external_id = $orderId;
            $payment->save();

            return new ChargeResult(
                status: PaymentStatus::Pending,
                externalId: $orderId,
                redirectUrl: $approveLink['href'] ?? null,
                raw: $data,
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

    public function capture(Payment $payment): ChargeResult
    {
        try {
            $accessToken = $this->getAccessToken();

            $response = Http::withToken($accessToken)
                ->contentType('application/json')
                ->post($this->baseUrl()."/v2/checkout/orders/{$payment->external_id}/capture");

            $data = $response->json();
            $flat = Arr::dot($data);

            if ($response->successful() && in_array($flat['status'] ?? '', ['COMPLETED', 'APPROVED'])) {
                return new ChargeResult(
                    status: PaymentStatus::Paid,
                    externalId: $payment->external_id,
                    authorizationCode: $flat['purchase_units.0.payments.captures.0.id'] ?? null,
                    raw: $data,
                );
            }

            return new ChargeResult(
                status: PaymentStatus::Failed,
                externalId: $payment->external_id,
                message: $flat['details.0.description'] ?? 'Payment capture failed',
                raw: $data,
            );
        } catch (\Throwable $e) {
            $this->logError('Capture failed', ['error' => $e->getMessage()]);

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
            $accessToken = $this->getAccessToken();

            $response = Http::withToken($accessToken)
                ->get($this->baseUrl()."/v2/checkout/orders/{$payment->external_id}");

            $data = $response->json();
            $status = match ($data['status'] ?? '') {
                'COMPLETED', 'APPROVED' => PaymentStatus::Paid,
                'VOIDED' => PaymentStatus::Reversed,
                default => PaymentStatus::Pending,
            };

            return new ChargeResult(
                status: $status,
                externalId: $payment->external_id,
                raw: $data,
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

    public function refund(Payment $payment, ?Money $amount = null): RefundResult
    {
        try {
            $accessToken = $this->getAccessToken();
            $captureId = $payment->authorization_code;

            $body = [];
            if ($amount) {
                $body['amount'] = [
                    'value' => number_format($amount->toDecimal(), 2, '.', ''),
                    'currency_code' => $amount->currency->value,
                ];
            }

            $response = Http::withToken($accessToken)
                ->contentType('application/json')
                ->post($this->baseUrl()."/v2/payments/captures/{$captureId}/refund", $body ?: null);

            $data = $response->json();

            if ($response->successful() && ($data['status'] ?? '') === 'COMPLETED') {
                return new RefundResult(
                    status: PaymentStatus::Refunded,
                    refundId: $data['id'] ?? null,
                    refundedAmount: $amount,
                    raw: $data,
                );
            }

            return new RefundResult(
                status: $payment->status,
                message: $data['message'] ?? 'Refund failed',
                raw: $data,
            );
        } catch (\Throwable $e) {
            $this->logError('Refund failed', ['error' => $e->getMessage()]);

            throw new GatewayCommunicationException(
                message: $e->getMessage(),
                gateway: $this->name(),
                previous: $e,
            );
        }
    }

    public function verifyWebhook(Request $request): bool
    {
        return $request->hasHeader('paypal-transmission-id');
    }

    public function handleWebhook(Request $request): void
    {
        $payload = $request->all();
        $eventType = $payload['event_type'] ?? '';

        if ($eventType === 'PAYMENT.CAPTURE.COMPLETED') {
            $orderId = $payload['resource']['supplementary_data']['related_ids']['order_id'] ?? null;
            if (! $orderId) {
                return;
            }

            $payment = Payment::where('external_id', $orderId)->first();
            if ($payment && $payment->isPending()) {
                $payment->status = PaymentStatus::Paid;
                $payment->paid_at = now();
                $payment->metadata = array_merge($payment->metadata ?? [], ['webhook' => $payload]);
                $payment->save();
            }
        }
    }

    protected function getAccessToken(): string
    {
        $response = Http::asForm()
            ->withBasicAuth($this->config['client_id'], $this->config['client_secret'])
            ->post($this->baseUrl().'/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
            ]);

        if (! $response->successful()) {
            throw new GatewayCommunicationException(
                message: 'PayPal auth error: '.$response->body(),
                gateway: $this->name(),
            );
        }

        return $response->json('access_token');
    }

    protected function baseUrl(): string
    {
        return $this->isSandbox()
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
    }
}
