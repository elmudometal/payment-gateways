<?php

namespace Arca\PaymentGateways\Drivers\Niubiz;

use Arca\PaymentGateways\Contracts\PaymentGateway;
use Arca\PaymentGateways\Drivers\AbstractDriver;
use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\Exceptions\GatewayCommunicationException;
use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\ValueObjects\ChargeIntent;
use Arca\PaymentGateways\ValueObjects\ChargeResult;
use Illuminate\Support\Facades\Http;

class NiubizDriver extends AbstractDriver implements PaymentGateway
{
    protected const array ACTION_CODES = [
        '000' => 'Aprobado y completado con éxito',
        '001' => 'Operación Denegada',
        '002' => 'Error en operación',
        '003' => 'Comercio Inválido',
        '004' => 'Retirar tarjeta',
        '005' => 'No honrar',
        '007' => 'Retener tarjeta - condiciones especiales',
        '012' => 'Transacción inválida',
        '013' => 'Monto inválido',
        '014' => 'Tarjeta no válida',
        '030' => 'Error de formato',
        '041' => 'Retener tarjeta - Extraviada',
        '043' => 'Retener tarjeta - Robada',
        '051' => 'Fondos insuficientes',
        '054' => 'Tarjeta vencida',
        '055' => 'PIN incorrecto',
        '056' => 'Tarjeta no encontrada',
        '091' => 'Emisor no disponible',
        '096' => 'Error del sistema',
    ];

    public function name(): string
    {
        return 'niubiz';
    }

    public function charge(Payment $payment, ChargeIntent $intent): ChargeResult
    {
        try {
            $accessToken = $this->getAccessToken();
            $sessionToken = $this->getSessionToken($accessToken, $intent->money->toDecimal());

            $payment->external_id = $sessionToken;
            $payment->save();

            return new ChargeResult(
                status: PaymentStatus::Pending,
                externalId: $sessionToken,
                raw: [
                    'sessionToken' => $sessionToken,
                    'merchantId' => $this->config['merchant_id'],
                    'jsUrl' => $this->jsUrl(),
                ],
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

    public function authorize(string $transactionToken, Payment $payment): ChargeResult
    {
        try {
            $accessToken = $this->getAccessToken();
            $response = $this->authorizeTransaction($accessToken, $transactionToken, $payment);

            $actionCode = $response['dataMap']['ACTION_CODE']
                ?? $response['order']['actionCode']
                ?? null;

            if ($actionCode === '000') {
                return new ChargeResult(
                    status: PaymentStatus::Paid,
                    externalId: $payment->external_id,
                    authorizationCode: $response['dataMap']['AUTHORIZATION_CODE']
                        ?? $response['order']['authorizationCode']
                        ?? null,
                    raw: $response,
                );
            }

            return new ChargeResult(
                status: PaymentStatus::Failed,
                externalId: $payment->external_id,
                message: self::ACTION_CODES[$actionCode] ?? 'Error desconocido',
                raw: $response,
            );
        } catch (\Throwable $e) {
            $this->logError('Authorize failed', ['error' => $e->getMessage()]);

            throw new GatewayCommunicationException(
                message: $e->getMessage(),
                gateway: $this->name(),
                previous: $e,
            );
        }
    }

    public function query(Payment $payment): ChargeResult
    {
        return new ChargeResult(
            status: $payment->status,
            externalId: $payment->external_id,
            authorizationCode: $payment->authorization_code,
        );
    }

    protected function getAccessToken(): string
    {
        $response = Http::withBasicAuth(
            $this->config['user'],
            $this->config['password'],
        )->get($this->baseUrl().'/api.security/v1/security');

        if (! $response->successful()) {
            throw new GatewayCommunicationException(
                message: 'Niubiz security API error: '.$response->body(),
                gateway: $this->name(),
            );
        }

        return $response->body();
    }

    protected function getSessionToken(string $accessToken, float $amount): string
    {
        $merchantId = $this->config['merchant_id'];
        $response = Http::withToken($accessToken)
            ->post($this->baseUrl()."/api.ecommerce/v2/ecommerce/token/session/{$merchantId}", [
                'channel' => 'web',
                'amount' => $amount,
                'antifraud' => [
                    'clientIp' => request()->ip(),
                    'merchantDefineData' => new \stdClass,
                ],
            ]);

        if (! $response->successful()) {
            throw new GatewayCommunicationException(
                message: 'Niubiz session token error: '.$response->body(),
                gateway: $this->name(),
            );
        }

        return $response->json('sessionKey');
    }

    /**
     * @return array<string, mixed>
     */
    protected function authorizeTransaction(string $accessToken, string $transactionToken, Payment $payment): array
    {
        $merchantId = $this->config['merchant_id'];
        $currency = $this->config['currency'] ?? 'PEN';

        $response = Http::withToken($accessToken)
            ->post($this->baseUrl()."/api.authorization/v3/authorization/ecommerce/{$merchantId}", [
                'channel' => 'web',
                'captureType' => 'manual',
                'countable' => true,
                'order' => [
                    'tokenId' => $transactionToken,
                    'purchaseNumber' => $payment->id,
                    'amount' => $payment->money()->toDecimal(),
                    'currency' => $currency,
                ],
            ]);

        if (! $response->successful()) {
            $error = $response->json();

            throw new GatewayCommunicationException(
                message: $error['errorMessage'] ?? 'Authorization failed: '.$response->body(),
                gateway: $this->name(),
            );
        }

        return $response->json();
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
