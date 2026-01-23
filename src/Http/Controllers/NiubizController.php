<?php

namespace Arca\PaymentGateways\Http\Controllers;

use Arca\PaymentGateways\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NiubizController extends Controller
{
    protected string $merchantId;

    protected string $user;

    protected string $password;

    protected string $apiUrl;

    protected string $jsUrl;

    public array $actionCodes = [
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
        '057' => 'Transacción no permitida al titular',
        '058' => 'Transacción no permitida en terminal',
        '059' => 'Sospecha de fraude',
        '061' => 'Excede límite de monto',
        '062' => 'Tarjeta restringida',
        '063' => 'Violación de seguridad',
        '065' => 'Excede límite de frecuencia',
        '075' => 'PIN intentos excedidos',
        '076' => 'Clave inválida',
        '077' => 'Inconsistencia datos clave',
        '078' => 'Tarjeta inexistente',
        '082' => 'CVV2 inválido',
        '089' => 'Terminal inválido',
        '091' => 'Emisor no disponible',
        '094' => 'Transacción duplicada',
        '096' => 'Error del sistema',
        '101' => 'Tarjeta Vencida',
        '102' => 'Tarjeta de Crédito Bloqueada',
        '106' => 'Excede límite de intentos',
        '111' => 'Saldo insuficiente',
        '116' => 'Fondos Insuficientes',
        '121' => 'Excede límite de monto',
        '161' => 'Cap excedido',
        '180' => 'Tarjeta inválida',
        '181' => 'Denegado, tarjeta con restricción',
        '182' => 'Denegado, verificar datos de tarjeta',
        '183' => 'Denegado, tarjeta inválida',
        '190' => 'Denegado, sin razón',
        '191' => 'Fecha de expiración incorrecta',
    ];

    public function __construct()
    {
        $this->merchantId = config('payment-gateways.niubiz.merchant_id');
        $this->user = config('payment-gateways.niubiz.user');
        $this->password = config('payment-gateways.niubiz.password');

        if (app()->environment('production')) {
            $this->apiUrl = 'https://apiprod.vnforapps.com';
            $this->jsUrl = 'https://static-content.vnforapps.com/v2/js/checkout.js';
        } else {
            $this->apiUrl = 'https://apisandbox.vnforappstest.com';
            $this->jsUrl = 'https://static-content-qas.vnforapps.com/v2/js/checkout.js';
        }
    }

    public function init(Payment $payment)
    {
        if ($payment->status === Payment::PAID_STATUS) {
            return redirect()->route('niubiz.successful', $payment->uuid);
        } elseif ($payment->status === Payment::CANCELED_STATUS) {
            return redirect()->route('niubiz.rejected', $payment->uuid);
        }

        try {
            $accessToken = $this->getAccessToken();
            $sessionToken = $this->getSessionToken($accessToken, $payment->amount);

            $payment->token = $sessionToken;
            $payment->save();

            return view('payment-gateways::niubiz.checkout', [
                'payment' => $payment,
                'sessionToken' => $sessionToken,
                'merchantId' => $this->merchantId,
                'jsUrl' => $this->jsUrl,
                'authorizeUrl' => route('niubiz.authorize', $payment->uuid),
            ]);
        } catch (\Exception $e) {
            Log::error('Niubiz init error: ' . $e->getMessage());

            return redirect()->route('niubiz.rejected', $payment->uuid);
        }
    }

    public function authorize(Payment $payment, Request $request)
    {
        try {
            $transactionToken = $request->input('transactionToken');

            if (empty($transactionToken)) {
                throw new \Exception('Transaction token is required');
            }

            $accessToken = $this->getAccessToken();
            $response = $this->authorizeTransaction($accessToken, $transactionToken, $payment);

            $payment->voucher = $response;

            $actionCode = $response['dataMap']['ACTION_CODE'] ?? $response['order']['actionCode'] ?? null;

            if ($actionCode === '000') {
                $payment->status = Payment::PAID_STATUS;
                $payment->authorization_code = $response['dataMap']['AUTHORIZATION_CODE'] ?? $response['order']['authorizationCode'] ?? null;
                $payment->save();

                return redirect()->route('niubiz.successful', $payment->uuid);
            }

            $payment->status = Payment::CANCELED_STATUS;
            $payment->voucher = array_merge($payment->voucher, [
                'message' => $this->actionCodes[$actionCode] ?? 'Error desconocido',
            ]);
            $payment->save();

            return redirect()->route('niubiz.rejected', $payment->uuid);
        } catch (\Exception $e) {
            Log::error('Niubiz authorize error: ' . $e->getMessage());

            $payment->status = Payment::CANCELED_STATUS;
            $payment->voucher = ['error' => $e->getMessage()];
            $payment->save();

            return redirect()->route('niubiz.rejected', $payment->uuid);
        }
    }

    public function successful(Payment $payment)
    {
        return $this->afterSuccessful($payment);
    }

    public function rejected(Payment $payment)
    {
        $error = $payment->voucher['message'] ?? $payment->voucher['error'] ?? 'Error desconocido';

        return $this->afterRejected($payment, $error);
    }

    /**
     * Obtener token de acceso (Paso 1)
     */
    protected function getAccessToken(): string
    {
        $url = $this->apiUrl . '/api.security/v1/security';

        $response = Http::withBasicAuth($this->user, $this->password)
            ->get($url);

        if (! $response->successful()) {
            throw new \Exception('Niubiz security API error: ' . $response->body());
        }

        return $response->body();
    }

    /**
     * Obtener token de sesión (Paso 2)
     */
    protected function getSessionToken(string $accessToken, float $amount): string
    {
        $url = $this->apiUrl . '/api.ecommerce/v2/ecommerce/token/session/' . $this->merchantId;

        $response = Http::withToken($accessToken)
            ->post($url, [
                'channel' => 'web',
                'amount' => $amount,
                'antifraud' => [
                    'clientIp' => request()->ip(),
                    'merchantDefineData' => new \stdClass,
                ],
            ]);

        if (! $response->successful()) {
            throw new \Exception('Niubiz session token error: ' . $response->body());
        }

        $data = $response->json();

        return $data['sessionKey'];
    }

    /**
     * Autorizar transacción (Paso 4)
     */
    protected function authorizeTransaction(string $accessToken, string $transactionToken, Payment $payment): array
    {
        $url = $this->apiUrl . '/api.authorization/v3/authorization/ecommerce/' . $this->merchantId;

        $response = Http::withToken($accessToken)
            ->post($url, [
                'channel' => 'web',
                'captureType' => 'manual',
                'countable' => true,
                'order' => [
                    'tokenId' => $transactionToken,
                    'purchaseNumber' => $payment->id,
                    'amount' => $payment->amount,
                    'currency' => 'PEN',
                ],
            ]);

        if (! $response->successful()) {
            $errorData = $response->json();
            throw new \Exception($errorData['errorMessage'] ?? 'Authorization failed: ' . $response->body());
        }

        return $response->json();
    }

    // Hooks sobrescribibles
    public function afterSuccessful(Payment $payment)
    {
        return view('payment-gateways::niubiz.successful', compact('payment'));
    }

    public function afterRejected(Payment $payment, string $error = '')
    {
        return view('payment-gateways::niubiz.rejected', compact('payment', 'error'));
    }
}
