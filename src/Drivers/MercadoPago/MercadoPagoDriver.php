<?php

namespace Arca\PaymentGateways\Drivers\MercadoPago;

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
use Illuminate\Support\Facades\Http;

class MercadoPagoDriver extends AbstractDriver implements PaymentGateway, SupportsRefunds, SupportsWebhooks
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

    public function name(): string
    {
        return 'mercadopago';
    }

    public function charge(Payment $payment, ChargeIntent $intent): ChargeResult
    {
        try {
            $response = $this->http()
                ->post('/checkout/preferences', [
                    'items' => [
                        [
                            'title' => $intent->description,
                            'quantity' => 1,
                            'unit_price' => $intent->money->toDecimal(),
                            'currency_id' => $intent->money->currency->value,
                        ],
                    ],
                    'payer' => array_filter([
                        'email' => $intent->customer?->email,
                        'name' => $intent->customer?->name,
                    ]),
                    'back_urls' => [
                        'success' => $intent->urls->returnUrl,
                        'failure' => $intent->urls->cancelUrl ?? $intent->urls->returnUrl,
                        'pending' => $intent->urls->returnUrl,
                    ],
                    'notification_url' => $intent->urls->webhookUrl,
                    'external_reference' => $payment->uuid,
                    'auto_return' => 'approved',
                ]);

            if (! $response->successful()) {
                return new ChargeResult(
                    status: PaymentStatus::Failed,
                    message: 'Failed to create MercadoPago preference',
                    raw: $response->json(),
                );
            }

            $data = $response->json();
            $preferenceId = $data['id'];

            $payment->external_id = $preferenceId;
            $payment->save();

            $redirectUrl = $this->isSandbox()
                ? $data['sandbox_init_point']
                : $data['init_point'];

            return new ChargeResult(
                status: PaymentStatus::Pending,
                externalId: $preferenceId,
                redirectUrl: $redirectUrl,
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

    public function query(Payment $payment): ChargeResult
    {
        try {
            $response = $this->http()
                ->get('/v1/payments/search', [
                    'external_reference' => $payment->uuid,
                    'sort' => 'date_created',
                    'criteria' => 'desc',
                    'limit' => 1,
                ]);

            if (! $response->successful()) {
                throw new GatewayCommunicationException(
                    message: 'MercadoPago search error: '.$response->body(),
                    gateway: $this->name(),
                );
            }

            $data = $response->json();
            $results = $data['results'] ?? [];

            if (empty($results)) {
                return new ChargeResult(
                    status: PaymentStatus::Pending,
                    externalId: $payment->external_id,
                );
            }

            $mpPayment = $results[0];

            return $this->mapPaymentResponse($mpPayment, $payment->external_id);
        } catch (GatewayCommunicationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logError('Query failed', ['error' => $e->getMessage()]);

            throw new GatewayCommunicationException(
                message: $e->getMessage(),
                gateway: $this->name(),
                previous: $e,
            );
        }
    }

    public function getPayment(int|string $paymentId): ChargeResult
    {
        $response = $this->http()->get("/v1/payments/{$paymentId}");

        if (! $response->successful()) {
            throw new GatewayCommunicationException(
                message: 'MercadoPago payment query error: '.$response->body(),
                gateway: $this->name(),
            );
        }

        return $this->mapPaymentResponse($response->json());
    }

    public function refund(Payment $payment, ?Money $amount = null): RefundResult
    {
        try {
            $mpPaymentId = $payment->metadata['mp_payment_id']
                ?? $payment->authorization_code;

            if (! $mpPaymentId) {
                return new RefundResult(
                    status: $payment->status,
                    message: 'No MercadoPago payment ID available for refund',
                );
            }

            $body = [];
            if ($amount) {
                $body['amount'] = $amount->toDecimal();
            }

            $response = $this->http()
                ->post("/v1/payments/{$mpPaymentId}/refunds", $body ?: null);

            $data = $response->json();

            if ($response->successful() && ($data['status'] ?? '') === 'approved') {
                return new RefundResult(
                    status: PaymentStatus::Refunded,
                    refundId: (string) ($data['id'] ?? ''),
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
        if ($request->has('data.id') && $request->has('type')) {
            return true;
        }

        if ($request->has('topic') && $request->has('id')) {
            return true;
        }

        return false;
    }

    public function handleWebhook(Request $request): void
    {
        $paymentId = $request->input('data.id') ?? $request->input('id');
        $topic = $request->input('type') ?? $request->input('topic');

        if ($topic !== 'payment') {
            return;
        }

        if (! $paymentId) {
            return;
        }

        try {
            $result = $this->getPayment($paymentId);
            $externalRef = $result->raw['external_reference'] ?? null;

            if (! $externalRef) {
                return;
            }

            $payment = Payment::where('uuid', $externalRef)->first();

            if (! $payment || $payment->status->isFinal()) {
                return;
            }

            $payment->status = $result->status;
            $payment->authorization_code = $result->authorizationCode ?? $payment->authorization_code;
            $payment->metadata = array_merge($payment->metadata ?? [], [
                'voucher' => $result->raw,
                'mp_payment_id' => $paymentId,
            ]);

            if ($result->isApproved() && ! $payment->paid_at) {
                $payment->paid_at = now();
            }

            $payment->save();
        } catch (\Throwable $e) {
            $this->logError('Webhook handling failed', ['error' => $e->getMessage(), 'payment_id' => $paymentId]);
        }
    }

    public function processReturn(Request $request, Payment $payment): ChargeResult
    {
        $mpPaymentId = $request->input('payment_id');
        $status = $request->input('status');
        $externalReference = $request->input('external_reference');

        if ($mpPaymentId) {
            $payment->metadata = array_merge($payment->metadata ?? [], [
                'mp_payment_id' => $mpPaymentId,
            ]);
            $payment->save();

            return $this->getPayment($mpPaymentId);
        }

        $mpStatus = self::STATUS_MAP[$status ?? ''] ?? PaymentStatus::Pending;

        return new ChargeResult(
            status: $mpStatus,
            externalId: $payment->external_id,
        );
    }

    protected function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withToken($this->config['access_token'])
            ->acceptJson()
            ->contentType('application/json')
            ->throw(fn ($response, $e) => throw new GatewayCommunicationException(
                message: "HTTP error from [{$this->name()}]: {$e->getMessage()}",
                gateway: $this->name(),
                context: ['status' => $response->status(), 'body' => $response->body()],
                previous: $e,
            ));
    }

    protected function baseUrl(): string
    {
        return 'https://api.mercadopago.com';
    }

    /**
     * @param array<string, mixed> $mpPayment
     */
    protected function mapPaymentResponse(array $mpPayment, ?string $externalId = null): ChargeResult
    {
        $status = self::STATUS_MAP[$mpPayment['status'] ?? ''] ?? PaymentStatus::Pending;

        return new ChargeResult(
            status: $status,
            externalId: $externalId ?? (string) ($mpPayment['id'] ?? ''),
            authorizationCode: isset($mpPayment['id']) ? (string) $mpPayment['id'] : null,
            message: $mpPayment['status_detail'] ?? null,
            raw: $mpPayment,
        );
    }
}
