<?php

namespace Arca\PaymentGateways\Drivers\Getnet;

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
use Dnetix\Redirection\Entities\Status;
use Dnetix\Redirection\Message\RedirectInformation;
use Dnetix\Redirection\PlacetoPay;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Getnet Chile Web Checkout (PlacetoPay). Needs dnetix/redirection ^2.1.
 *
 * Getnet sends notifications to the URL configured for the site in its panel: set it to the
 * `payments.webhook` route for "getnet".
 */
class GetnetDriver extends AbstractDriver implements SupportsRefunds, SupportsWebhooks
{
    protected ?PlacetoPay $placetoPay = null;

    protected ?Client $client = null;

    public function name(): string
    {
        return 'getnet';
    }

    /**
     * Getnet references are limited to 32 characters: the uuid without dashes.
     */
    public function reference(Payment $payment): string
    {
        return str_replace('-', '', $payment->uuid);
    }

    /**
     * Replaces the HTTP client of the PlacetoPay library (tests).
     */
    public function usingHttpClient(Client $client): static
    {
        $this->client = $client;
        $this->placetoPay = null;

        return $this;
    }

    public function charge(Payment $payment, ChargeIntent $intent): ChargeResult
    {
        $buyer = array_filter([
            'name' => $intent->customer?->name,
            'email' => $intent->customer?->email,
            'mobile' => $intent->customer?->phone,
        ]);

        $request = [
            'locale' => $this->config['locale'] ?? 'es_CL',
            'payment' => [
                'reference' => $this->reference($payment),
                'description' => Str::limit($intent->description, 240),
                'amount' => [
                    'currency' => $intent->money->currency->value,
                    'total' => $intent->money->toDecimal(),
                ],
                'allowPartial' => false,
            ],
            'expiration' => now()->addMinutes((int) ($this->config['expiration'] ?? 60))->toAtomString(),
            'returnUrl' => $intent->urls->returnUrl,
            'cancelUrl' => $intent->urls->cancelUrl ?? $intent->urls->returnUrl,
            'ipAddress' => $intent->customer->ip ?? request()->ip() ?? '127.0.0.1',
            'userAgent' => request()->userAgent() ?? 'arca/payment-gateways',
        ];

        if ($buyer !== []) {
            $request['buyer'] = $buyer;
        }

        try {
            $response = $this->placetoPay()->request($request);
        } catch (Throwable $e) {
            throw $this->communicationError($e, 'Create session');
        }

        if (! $response->isSuccessful()) {
            throw new GatewayCommunicationException(
                message: 'Getnet refused the session: '.$response->status()->message(),
                gateway: $this->name(),
                context: $response->status()->toArray(),
            );
        }

        return new ChargeResult(
            status: PaymentStatus::Pending,
            externalId: $response->requestId(),
            redirectUrl: $response->processUrl(),
        );
    }

    /**
     * The buyer is back (paid or cancelled): the session is read from the API.
     */
    public function complete(Payment $payment, Request $request): ChargeResult
    {
        return $this->query($payment);
    }

    public function query(Payment $payment): ChargeResult
    {
        try {
            $information = $this->placetoPay()->query((int) $payment->external_id);
        } catch (Throwable $e) {
            throw $this->communicationError($e, 'Query session');
        }

        if ($information->status()->isError() || $information->requestId() === '') {
            throw new GatewayCommunicationException(
                message: 'Getnet query failed: '.$information->status()->message(),
                gateway: $this->name(),
                context: $information->status()->toArray(),
            );
        }

        return $this->mapInformation($information);
    }

    /**
     * Getnet only reverses a whole transaction.
     */
    public function refund(Payment $payment, ?Money $amount = null): RefundResult
    {
        if ($amount !== null && ! $amount->equals($payment->money())) {
            return new RefundResult(status: $payment->status, message: 'Getnet only supports full refunds.');
        }

        if ($payment->transaction_id === null) {
            return new RefundResult(status: $payment->status, message: 'No Getnet internal reference stored for this payment.');
        }

        try {
            $response = $this->placetoPay()->reverse($payment->transaction_id);
        } catch (Throwable $e) {
            throw $this->communicationError($e, 'Reverse');
        }

        $approved = $response->status()->isApproved();

        return new RefundResult(
            status: $approved ? PaymentStatus::Refunded : $payment->status,
            refundId: $response->payment()?->internalReference(),
            refundedAmount: $approved ? $payment->money() : null,
            message: $approved ? null : $response->status()->message(),
            raw: $response->status()->toArray(),
        );
    }

    /**
     * Notifications are signed with sha1(requestId + status + date + tranKey), or sha256 when the
     * signature is prefixed with "sha256:".
     */
    public function verifyWebhook(Request $request): bool
    {
        $requestId = (string) $request->input('requestId', '');
        $status = (string) $request->input('status.status', '');
        $date = (string) $request->input('status.date', '');
        $signature = (string) $request->input('signature', '');

        $tranKey = (string) ($this->config['tran_key'] ?? '');

        if ($requestId === '' || $status === '' || $signature === '' || $tranKey === '') {
            return false;
        }

        $data = $requestId.$status.$date.$tranKey;

        $expected = str_starts_with($signature, 'sha256:')
            ? 'sha256:'.hash('sha256', $data)
            : sha1($data);

        return hash_equals($expected, $signature);
    }

    public function handleWebhook(Request $request): ?WebhookResult
    {
        $payment = Payment::query()
            ->where('gateway', $this->gateway())
            ->where('external_id', (string) $request->input('requestId'))
            ->first();

        return $payment ? new WebhookResult($payment, $this->query($payment)) : null;
    }

    protected function mapInformation(RedirectInformation $information): ChargeResult
    {
        $status = match ($information->status()->status()) {
            Status::ST_APPROVED => PaymentStatus::Paid,
            Status::ST_REJECTED, Status::ST_FAILED => PaymentStatus::Failed,
            Status::ST_REFUNDED => PaymentStatus::Refunded,
            default => PaymentStatus::Pending,
        };

        $sent = $information->request()?->payment();
        $transaction = $information->lastApprovedTransaction() ?? $information->lastTransaction();
        $amount = null;

        if ($sent?->amount() !== null) {
            $currency = Currency::tryFrom($sent->amount()->currency())
                ?? throw new PaymentVerificationException(
                    message: "Unsupported Getnet currency [{$sent->amount()->currency()}].",
                    gateway: $this->name(),
                );

            $amount = Money::fromDecimal($sent->amount()->total(), $currency);
        }

        return new ChargeResult(
            status: $status,
            externalId: $information->requestId(),
            transactionId: $status === PaymentStatus::Paid ? $transaction?->internalReference() : null,
            authorizationCode: $status === PaymentStatus::Paid ? ($transaction?->authorization() ?: null) : null,
            reference: $sent?->reference(),
            amount: $amount,
            message: $status === PaymentStatus::Failed ? ($transaction?->status()->message() ?: $information->status()->message()) : null,
            raw: [
                'request_id' => $information->requestId(),
                'status' => $information->status()->toArray(),
                'transaction' => $transaction ? [
                    'internal_reference' => $transaction->internalReference(),
                    'authorization' => $transaction->authorization(),
                    'payment_method' => $transaction->paymentMethodName(),
                    'franchise' => $transaction->franchise(),
                    'receipt' => $transaction->receipt(),
                    'status' => $transaction->status()->toArray(),
                ] : null,
            ],
        );
    }

    protected function placetoPay(): PlacetoPay
    {
        $this->requireConfig(['login', 'tran_key']);

        return $this->placetoPay ??= new PlacetoPay(array_filter([
            'login' => (string) $this->config['login'],
            'tranKey' => (string) $this->config['tran_key'],
            'baseUrl' => $this->baseUrl(),
            'timeout' => (int) config('payment-gateways.http.timeout', 15),
            'client' => $this->client,
        ]));
    }

    protected function baseUrl(): string
    {
        return $this->isSandbox()
            ? 'https://checkout.test.getnet.cl'
            : 'https://checkout.getnet.cl';
    }
}
