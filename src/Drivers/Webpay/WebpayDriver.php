<?php

namespace Arca\PaymentGateways\Drivers\Webpay;

use Arca\PaymentGateways\Contracts\PaymentGateway;
use Arca\PaymentGateways\Contracts\SupportsWebhooks;
use Arca\PaymentGateways\Drivers\AbstractDriver;
use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\Exceptions\GatewayCommunicationException;
use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\ValueObjects\ChargeIntent;
use Arca\PaymentGateways\ValueObjects\ChargeResult;
use Illuminate\Http\Request;
use Transbank\Webpay\WebpayPlus;
use Transbank\Webpay\WebpayPlus\Transaction;

class WebpayDriver extends AbstractDriver implements PaymentGateway, SupportsWebhooks
{
    protected const array RESPONSE_CODES = [
        '0' => 'Transacción aprobada',
        '-1' => 'Rechazo de transacción',
        '-2' => 'Transacción debe reintentarse',
        '-3' => 'Error en transacción',
        '-4' => 'Rechazo de transacción',
        '-5' => 'Rechazo por error de tasa',
        '-6' => 'Excede cupo máximo mensual',
        '-7' => 'Excede límite diario por transacción',
        '-8' => 'Rubro no autorizado',
    ];

    protected const array PAYMENT_TYPE_CODES = [
        'VD' => 'Venta Débito',
        'VN' => 'Venta Normal',
        'VC' => 'Venta en cuotas',
        'SI' => '3 cuotas sin interés',
        'S2' => '2 cuotas sin interés',
        'NC' => 'N Cuotas sin interés',
        'VP' => 'Venta Prepago',
    ];

    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->configureTransbank();
    }

    public function name(): string
    {
        return 'webpay';
    }

    public function charge(Payment $payment, ChargeIntent $intent): ChargeResult
    {
        try {
            $transaction = new Transaction;
            $response = $transaction->create(
                (string) $payment->id,
                session()->getId(),
                $intent->money->amount,
                $intent->urls->returnUrl,
            );

            $token = $response->getToken();
            $url = $response->getUrl();

            if (empty($token)) {
                return new ChargeResult(
                    status: PaymentStatus::Failed,
                    message: 'No se obtuvo token de Webpay',
                );
            }

            $payment->external_id = $token;
            $payment->save();

            return new ChargeResult(
                status: PaymentStatus::Pending,
                externalId: $token,
                redirectUrl: $url.'?token_ws='.$token,
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
            $response = (new Transaction)->status($payment->external_id);
            $raw = json_decode(json_encode($response), true);

            if ($response->isApproved()) {
                return new ChargeResult(
                    status: PaymentStatus::Paid,
                    externalId: $payment->external_id,
                    authorizationCode: (string) $response->authorizationCode,
                    raw: $raw,
                );
            }

            $responseCode = (string) ($raw['responseCode'] ?? '');

            return new ChargeResult(
                status: PaymentStatus::Failed,
                externalId: $payment->external_id,
                message: self::RESPONSE_CODES[$responseCode] ?? 'Error desconocido',
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

    public function commit(Request $request, Payment $payment): ChargeResult
    {
        $flowStatus = $this->detectTransactionFlow($request);

        if ($flowStatus === 'normal') {
            return $this->commitNormal($request, $payment);
        }

        return $this->commitAbnormal($flowStatus, $payment);
    }

    protected function commitNormal(Request $request, Payment $payment): ChargeResult
    {
        $response = (new Transaction)->commit($request->input('token_ws'));
        $raw = json_decode(json_encode($response), true);

        if ($response->isApproved()) {
            return new ChargeResult(
                status: PaymentStatus::Paid,
                externalId: $payment->external_id,
                authorizationCode: (string) $response->authorizationCode,
                raw: $raw,
            );
        }

        $responseCode = (string) ($raw['responseCode'] ?? '');

        return new ChargeResult(
            status: PaymentStatus::Failed,
            externalId: $payment->external_id,
            message: self::RESPONSE_CODES[$responseCode] ?? 'Transacción rechazada',
            raw: $raw,
        );
    }

    protected function commitAbnormal(string $flowStatus, Payment $payment): ChargeResult
    {
        $messages = [
            'user_aborted' => 'El usuario abortó el pago.',
            'error_occurred' => 'Ocurrió un error en el formulario de pago.',
            'timeout' => 'La transacción expiró por inactividad.',
        ];

        try {
            $response = (new Transaction)->status($payment->external_id);
            $raw = json_decode(json_encode($response), true);

            if ($response->isApproved()) {
                return new ChargeResult(
                    status: PaymentStatus::Paid,
                    externalId: $payment->external_id,
                    authorizationCode: (string) $response->authorizationCode,
                    raw: $raw,
                );
            }

            return new ChargeResult(
                status: PaymentStatus::Failed,
                externalId: $payment->external_id,
                message: $messages[$flowStatus] ?? 'Estado desconocido',
                raw: $raw,
            );
        } catch (\Throwable $e) {
            return new ChargeResult(
                status: PaymentStatus::Failed,
                externalId: $payment->external_id,
                message: $messages[$flowStatus] ?? $e->getMessage(),
            );
        }
    }

    public function detectTransactionFlow(Request $request): string
    {
        $tokenWs = $request->input('token_ws');
        $tbkToken = $request->input('TBK_TOKEN');
        $ordenCompra = $request->input('TBK_ORDEN_COMPRA');
        $idSesion = $request->input('TBK_ID_SESION');

        if ($tokenWs && ! $ordenCompra && ! $idSesion && ! $tbkToken) {
            return 'normal';
        }

        if ($tbkToken && $ordenCompra && $idSesion && ! $tokenWs) {
            return 'user_aborted';
        }

        if ($tokenWs && $ordenCompra && $idSesion && $tbkToken) {
            return 'error_occurred';
        }

        if ($ordenCompra && $idSesion && ! $tokenWs && ! $tbkToken) {
            return 'timeout';
        }

        return 'unknown';
    }

    public function verifyWebhook(Request $request): bool
    {
        return $request->has('token_ws') || $request->has('TBK_TOKEN');
    }

    public function handleWebhook(Request $request): void
    {
        // Webpay uses return URL flow, not server-to-server webhooks
    }

    protected function baseUrl(): string
    {
        return '';
    }

    protected function configureTransbank(): void
    {
        if ($this->isSandbox()) {
            WebpayPlus::configureForTesting();
        } else {
            WebpayPlus::configureForProduction(
                $this->config['commerce_code'],
                $this->config['api_key'],
            );
        }
    }
}
