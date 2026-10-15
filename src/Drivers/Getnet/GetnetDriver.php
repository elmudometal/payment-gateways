<?php

namespace Arca\PaymentGateways\Drivers\Getnet;

use Arca\PaymentGateways\Contracts\PaymentGateway;
use Arca\PaymentGateways\Contracts\SupportsWebhooks;
use Arca\PaymentGateways\Drivers\AbstractDriver;
use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\Exceptions\GatewayCommunicationException;
use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\ValueObjects\ChargeIntent;
use Arca\PaymentGateways\ValueObjects\ChargeResult;
use Carbon\Carbon;
use DateTime;
use Dnetix\Redirection\PlacetoPay;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GetnetDriver extends AbstractDriver implements PaymentGateway, SupportsWebhooks
{
    protected PlacetoPay $placetoPay;

    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->initPlacetoPay();
    }

    public function name(): string
    {
        return 'getnet';
    }

    public function charge(Payment $payment, ChargeIntent $intent): ChargeResult
    {
        try {
            $transaction = [
                'locale' => 'es_CL',
                'expiration' => Carbon::now()->addHour()->format(DateTime::ATOM),
                'ipAddress' => $intent->customer?->ip ?? request()->ip(),
                'userAgent' => 'Arca/PaymentGateways',
                'returnUrl' => $intent->urls->returnUrl,
                'cancelUrl' => $intent->urls->cancelUrl ?? $intent->urls->returnUrl,
                'payment' => [
                    'reference' => $payment->id,
                    'description' => Str::limit($intent->description, 240),
                    'amount' => [
                        'currency' => $intent->money->currency->value,
                        'total' => $intent->money->toDecimal(),
                    ],
                    'allowPartial' => false,
                ],
            ];

            if ($intent->urls->webhookUrl) {
                $transaction['notificationUrl'] = $intent->urls->webhookUrl;
            }

            $response = $this->placetoPay->request($transaction);

            if ($response->isSuccessful()) {
                $payment->external_id = (string) $response->requestId();
                $payment->save();

                return new ChargeResult(
                    status: PaymentStatus::Pending,
                    externalId: (string) $response->requestId(),
                    redirectUrl: $response->processUrl(),
                );
            }

            return new ChargeResult(
                status: PaymentStatus::Failed,
                message: $response->status()->message(),
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
            $response = $this->placetoPay->query((int) $payment->external_id);
            $raw = $response->toArray();

            if ($response->isSuccessful() && $response->status()->isApproved()) {
                return new ChargeResult(
                    status: PaymentStatus::Paid,
                    externalId: $payment->external_id,
                    authorizationCode: $raw['payment'][0]['authorization'] ?? null,
                    raw: $raw,
                );
            }

            return new ChargeResult(
                status: PaymentStatus::Failed,
                externalId: $payment->external_id,
                message: $response->status()->message(),
                raw: $raw,
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
        return $request->has('reference');
    }

    public function handleWebhook(Request $request): void
    {
        $payment = Payment::where('id', $request->input('reference'))->first();

        if (! $payment) {
            return;
        }

        $result = $this->query($payment);

        $payment->status = $result->status;
        $payment->authorization_code = $result->authorizationCode ?? $payment->authorization_code;

        if ($result->raw) {
            $payment->metadata = array_merge($payment->metadata ?? [], ['voucher' => $result->raw]);
        }

        if ($result->isApproved() && ! $payment->paid_at) {
            $payment->paid_at = now();
        }

        $payment->save();
    }

    protected function baseUrl(): string
    {
        return $this->isSandbox()
            ? 'https://checkout.test.getnet.cl'
            : 'https://checkout.getnet.cl';
    }

    protected function initPlacetoPay(): void
    {
        if ($this->isSandbox()) {
            $this->placetoPay = new PlacetoPay([
                'login' => '7ffbb7bf1f7361b1200b2e8d74e1d76f',
                'tranKey' => 'SnZP3D63n3I9dH9O',
                'baseUrl' => $this->baseUrl(),
                'type' => 'rest',
            ]);
        } else {
            $this->placetoPay = new PlacetoPay([
                'login' => $this->config['login'],
                'tranKey' => $this->config['tran_key'],
                'baseUrl' => $this->baseUrl(),
                'type' => 'rest',
            ]);
        }
    }
}
