<?php

namespace Arca\PaymentGateways\Drivers\Niubiz;

use Arca\PaymentGateways\Drivers\AbstractDriver;
use Arca\PaymentGateways\Enums\Currency;
use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\Exceptions\GatewayCommunicationException;
use Arca\PaymentGateways\Exceptions\PaymentVerificationException;
use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\ValueObjects\ChargeIntent;
use Arca\PaymentGateways\ValueObjects\ChargeResult;
use Arca\PaymentGateways\ValueObjects\Money;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Throwable;

/**
 * Niubiz (Visanet Perú) web checkout: the card form is embedded in our page and posts a
 * transaction token back, which is authorized from the server.
 */
class NiubizDriver extends AbstractDriver
{
    public function name(): string
    {
        return 'niubiz';
    }

    /**
     * Niubiz purchase numbers are numeric, up to 12 digits.
     */
    public function reference(Payment $payment): string
    {
        return (string) $payment->id;
    }

    public function charge(Payment $payment, ChargeIntent $intent): ChargeResult
    {
        $token = $this->accessToken();

        try {
            $session = $this->api($token)
                ->post("/api.ecommerce/v2/ecommerce/token/session/{$this->config['merchant_id']}", [
                    'channel' => 'web',
                    'amount' => $intent->money->toDecimal(),
                    'antifraud' => [
                        'clientIp' => $intent->customer->ip ?? request()->ip(),
                        'merchantDefineData' => array_filter([
                            'MDD4' => $intent->customer?->email,
                        ]) ?: (object) [],
                    ],
                ])
                ->json();
        } catch (Throwable $e) {
            throw $this->communicationError($e, 'Create session');
        }

        return new ChargeResult(
            status: PaymentStatus::Pending,
            externalId: (string) $session['sessionKey'],
            view: 'payment-gateways::niubiz',
            viewData: [
                'sessionKey' => $session['sessionKey'],
                'merchantId' => $this->config['merchant_id'],
                'purchaseNumber' => $this->reference($payment),
                'amount' => $intent->money->toDecimalString(),
                'jsUrl' => $this->jsUrl(),
                'action' => $intent->urls->returnUrl,
                'timeoutUrl' => $intent->urls->cancelUrl ?? $intent->urls->returnUrl,
                'logo' => $this->config['logo'] ?? null,
                'email' => $intent->customer?->email,
            ],
        );
    }

    /**
     * The Niubiz form posts transactionToken; it is authorized here. Coming back without one
     * means the buyer closed the form or it timed out.
     */
    public function complete(Payment $payment, Request $request): ChargeResult
    {
        $transactionToken = (string) $request->input('transactionToken', '');

        if ($transactionToken === '') {
            return new ChargeResult(
                status: PaymentStatus::Failed,
                message: __('payment-gateways::messages.cancelled'),
            );
        }

        return $this->authorize($transactionToken, $payment);
    }

    /**
     * Niubiz has no status query for web checkout sessions: the authorization answer is final.
     */
    public function query(Payment $payment): ChargeResult
    {
        return new ChargeResult(status: $payment->status);
    }

    public function authorize(string $transactionToken, Payment $payment): ChargeResult
    {
        $token = $this->accessToken();

        try {
            $data = $this->api($token)
                ->post("/api.authorization/v3/authorization/ecommerce/{$this->config['merchant_id']}", [
                    'channel' => 'web',
                    'captureType' => 'manual',
                    'countable' => true,
                    'order' => [
                        'tokenId' => $transactionToken,
                        'purchaseNumber' => $this->reference($payment),
                        'amount' => $payment->money()->toDecimal(),
                        'currency' => $payment->currency->value,
                    ],
                ])
                ->json();
        } catch (GatewayCommunicationException $e) {
            // A declined card answers 400 with the action code in the body.
            $body = $e->context['body'] ?? null;

            if (($e->context['status'] ?? null) === 400 && is_array($body) && isset($body['data'])) {
                return $this->declined($body);
            }

            throw $this->communicationError($e, 'Authorize');
        } catch (Throwable $e) {
            throw $this->communicationError($e, 'Authorize');
        }

        $order = $data['order'] ?? [];
        $actionCode = (string) ($data['dataMap']['ACTION_CODE'] ?? $order['actionCode'] ?? '');
        $approved = $actionCode === '000';

        $currency = Currency::tryFrom((string) ($order['currency'] ?? ''))
            ?? throw new PaymentVerificationException(
                message: 'Unsupported Niubiz currency ['.($order['currency'] ?? '').'].',
                gateway: $this->name(),
            );

        return new ChargeResult(
            status: $approved ? PaymentStatus::Paid : PaymentStatus::Failed,
            transactionId: $data['dataMap']['TRANSACTION_ID'] ?? $order['transactionId'] ?? null,
            authorizationCode: $data['dataMap']['AUTHORIZATION_CODE'] ?? $order['authorizationCode'] ?? null,
            reference: isset($order['purchaseNumber']) ? (string) $order['purchaseNumber'] : null,
            amount: Money::fromDecimal($order['amount'] ?? 0, $currency),
            message: $approved ? null : $this->actionMessage($actionCode),
            raw: [
                'action_code' => $actionCode,
                'status' => $data['dataMap']['STATUS'] ?? null,
                'card' => $data['dataMap']['CARD'] ?? null,
                'brand' => $data['dataMap']['BRAND'] ?? null,
                'transaction_date' => $data['dataMap']['TRANSACTION_DATE'] ?? null,
                'purchase_number' => $order['purchaseNumber'] ?? null,
                'amount' => $order['amount'] ?? null,
                'currency' => $order['currency'] ?? null,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function declined(array $body): ChargeResult
    {
        $actionCode = (string) ($body['data']['ACTION_CODE'] ?? '');

        return new ChargeResult(
            status: PaymentStatus::Failed,
            message: $this->actionMessage($actionCode, $body['data']['ACTION_DESCRIPTION'] ?? null),
            raw: [
                'action_code' => $actionCode,
                'status' => $body['data']['STATUS'] ?? null,
                'card' => $body['data']['CARD'] ?? null,
                'brand' => $body['data']['BRAND'] ?? null,
                'error_code' => $body['errorCode'] ?? null,
            ],
        );
    }

    protected function actionMessage(string $code, ?string $fallback = null): string
    {
        $key = "payment-gateways::niubiz.action_codes.{$code}";

        if ($code !== '' && Lang::has($key)) {
            return __($key);
        }

        return $fallback ?: __('payment-gateways::messages.unknown_error');
    }

    protected function accessToken(): string
    {
        $this->requireConfig(['merchant_id', 'user', 'password']);

        try {
            return $this->http(retry: true)
                ->withBasicAuth((string) $this->config['user'], (string) $this->config['password'])
                ->accept('text/plain')
                ->get('/api.security/v1/security')
                ->body();
        } catch (Throwable $e) {
            throw $this->communicationError($e, 'Get access token');
        }
    }

    /**
     * Niubiz expects the raw token in the Authorization header, without "Bearer".
     */
    protected function api(string $token): PendingRequest
    {
        return $this->http()->withHeaders(['Authorization' => $token]);
    }

    protected function baseUrl(): string
    {
        return $this->isSandbox()
            ? 'https://apisandbox.vnforappstest.com'
            : 'https://apiprod.vnforapps.com';
    }

    protected function jsUrl(): string
    {
        return $this->isSandbox()
            ? 'https://static-content-qas.vnforapps.com/v2/js/checkout.js'
            : 'https://static-content.vnforapps.com/v2/js/checkout.js';
    }
}
