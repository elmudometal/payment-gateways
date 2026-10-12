<?php

namespace Arca\PaymentGateways\Http\Controllers;

use Arca\PaymentGateways\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MercadoPagoController extends Controller
{
    protected string $apiUrl = 'https://api.mercadopago.com';

    protected string $accessToken;

    protected string $webhookSecret;

    protected string $currency;

    public array $statusDetails = [
        'accredited' => 'Pago acreditado',
        'pending_contingency' => 'Estamos procesando el pago',
        'pending_review_manual' => 'El pago está en revisión',
        'pending_waiting_payment' => 'Esperando que se realice el pago',
        'pending_waiting_transfer' => 'Esperando la transferencia',
        'cc_rejected_bad_filled_card_number' => 'Revisa el número de tarjeta',
        'cc_rejected_bad_filled_date' => 'Revisa la fecha de vencimiento',
        'cc_rejected_bad_filled_other' => 'Revisa los datos de la tarjeta',
        'cc_rejected_bad_filled_security_code' => 'Revisa el código de seguridad de la tarjeta',
        'cc_rejected_blacklist' => 'No pudimos procesar tu pago',
        'cc_rejected_call_for_authorize' => 'Debes autorizar el pago ante el emisor de tu tarjeta',
        'cc_rejected_card_disabled' => 'Debes activar tu tarjeta con el emisor',
        'cc_rejected_card_error' => 'No pudimos procesar tu pago',
        'cc_rejected_duplicated_payment' => 'Ya hiciste un pago por ese valor',
        'cc_rejected_high_risk' => 'Tu pago fue rechazado por prevención de fraude',
        'cc_rejected_insufficient_amount' => 'Tu tarjeta no tiene fondos suficientes',
        'cc_rejected_invalid_installments' => 'Tu tarjeta no procesa pagos en esa cantidad de cuotas',
        'cc_rejected_max_attempts' => 'Llegaste al límite de intentos permitidos',
        'cc_rejected_other_reason' => 'El emisor de la tarjeta no procesó el pago',
        'cc_amount_rate_limit_exceeded' => 'Superaste el límite disponible de tu medio de pago',
        'rejected_high_risk' => 'Tu pago fue rechazado por prevención de fraude',
        'rejected_by_bank' => 'Operación rechazada por el banco',
        'rejected_insufficient_data' => 'Faltan datos obligatorios para procesar el pago',
        'expired' => 'El pago expiró',
        'by_collector' => 'Pago cancelado por el comercio',
        'by_payer' => 'Pago cancelado por el comprador',
    ];

    public function __construct()
    {
        $this->accessToken = (string) config('payment-gateways.mercadopago.access_token');
        $this->webhookSecret = (string) config('payment-gateways.mercadopago.webhook_secret');
        $this->currency = (string) config('payment-gateways.mercadopago.currency');
    }

    public function init(Payment $payment)
    {
        if ($payment->status === Payment::PAID_STATUS) {
            return redirect()->route('mercadopago.successful', $payment->uuid);
        } elseif ($payment->status === Payment::CANCELED_STATUS) {
            return redirect()->route('mercadopago.rejected', $payment->uuid);
        }

        $commitUrl = route('mercadopago.commit', $payment->uuid);
        $notificationUrl = route('mercadopago.notification', $payment->uuid);

        $preference = [
            'items' => [
                [
                    'id' => (string) $payment->id,
                    'title' => $payment->comments ?: (string) config('payment-gateways.mercadopago.commerce_name'),
                    'quantity' => 1,
                    'currency_id' => $this->currency,
                    'unit_price' => (float) $payment->amount,
                ],
            ],
            'external_reference' => $payment->uuid,
            'back_urls' => [
                'success' => $commitUrl,
                'pending' => $commitUrl,
                'failure' => $commitUrl,
            ],
            // Mercado Pago rejects the whole preference when auto_return is set with a localhost or
            // plain-HTTP back URL, and cannot deliver webhooks to a private address. Both are only
            // sent for public HTTPS URLs, so the checkout also works in local development.
            'auto_return' => $this->isPublicUrl($commitUrl) ? 'approved' : null,
            'notification_url' => $this->isPublicUrl($notificationUrl) ? $notificationUrl : null,
            'statement_descriptor' => (string) config('payment-gateways.mercadopago.statement_descriptor') ?: null,
        ];

        $email = $payment->model?->email; // @phpstan-ignore-line
        if (! empty($email)) {
            $preference['payer'] = ['email' => $email];
        }

        try {
            $response = $this->send('checkout/preferences', array_filter($preference), 'POST');

            $payment->token = $response['id'];
            $payment->save();

            return redirect()->away($response['init_point']);
        } catch (\Exception $e) {
            Log::error('MercadoPago init error: '.$e->getMessage());

            return redirect()->route('mercadopago.rejected', $payment->uuid);
        }
    }

    /**
     * Retorno del comprador desde Checkout Pro (back_urls).
     * Nunca se confía en el status del query string: se consulta el pago en la API.
     */
    public function commit(Payment $payment, Request $request)
    {
        $paymentId = $request->query('payment_id') ?? $request->query('collection_id');

        if (! empty($paymentId) && $paymentId !== 'null' && $payment->status === Payment::PENDING_STATUS) {
            try {
                $this->syncPayment($payment, (string) $paymentId);
            } catch (\Exception $e) {
                Log::error('MercadoPago commit error: '.$e->getMessage());
            }
        }

        if ($payment->status === Payment::PAID_STATUS) {
            return redirect()->route('mercadopago.successful', $payment->uuid);
        }

        if ($payment->status === Payment::PENDING_STATUS && ! empty($payment->voucher['id'])) {
            return redirect()->route('mercadopago.pending', $payment->uuid);
        }

        return redirect()->route('mercadopago.rejected', $payment->uuid);
    }

    /**
     * Webhook (notification_url) de Mercado Pago.
     */
    public function notification(Payment $payment, Request $request)
    {
        // PHP convierte "data.id" del query string en "data_id"
        $type = $request->query('type') ?? $request->query('topic') ?? $request->input('type');
        $dataId = $request->query('data_id') ?? $request->query('id') ?? $request->input('data.id');

        if ($type !== 'payment' || empty($dataId)) {
            return response()->json(['message' => 'ignored']);
        }

        if (! $this->validSignature($request, (string) $dataId)) {
            Log::warning('MercadoPago notification with invalid signature', ['payment' => $payment->uuid]);

            return response()->json(['message' => 'invalid signature'], 401);
        }

        try {
            $this->syncPayment($payment, (string) $dataId);

            return response()->json(['message' => 'success']);
        } catch (\Exception $e) {
            Log::error('MercadoPago notification error: '.$e->getMessage());

            return response()->json(['message' => 'error'], 500);
        }
    }

    public function successful(Payment $payment)
    {
        if ($payment->status !== Payment::PAID_STATUS) {
            return redirect()->route('mercadopago.rejected', $payment->uuid);
        }

        return $this->afterSuccessful($payment);
    }

    public function pending(Payment $payment)
    {
        return $this->afterPending($payment);
    }

    public function rejected(Payment $payment)
    {
        $detail = $payment->voucher['status_detail'] ?? null;
        $error = $this->statusDetails[$detail] ?? $payment->voucher['error'] ?? 'Error desconocido';

        return $this->afterRejected($payment, $error);
    }

    // Métodos auxiliares

    /**
     * Consulta el pago en Mercado Pago y actualiza el registro local.
     */
    protected function syncPayment(Payment $payment, string $mpPaymentId): void
    {
        $response = $this->send('v1/payments/'.$mpPaymentId);

        if (($response['external_reference'] ?? null) !== $payment->uuid) {
            throw new \Exception("external_reference mismatch for payment {$payment->uuid} (MP {$mpPaymentId})");
        }

        // No retroceder un pago ya aprobado por notificaciones fuera de orden
        if ($payment->status === Payment::PAID_STATUS && $response['status'] !== 'refunded' && $response['status'] !== 'charged_back') {
            return;
        }

        if ($response['status'] === 'approved' && (float) $response['transaction_amount'] < (float) $payment->amount) {
            throw new \Exception("Amount mismatch for payment {$payment->uuid}: {$response['transaction_amount']} < {$payment->amount}");
        }

        $payment->voucher = $response;
        $payment->status = $this->mapStatus($response['status']);

        if ($payment->status === Payment::PAID_STATUS) {
            $payment->authorization_code = (string) ($response['authorization_code'] ?? $response['id']);
        }

        $payment->save();
    }

    /**
     * Whether Mercado Pago can reach the URL: HTTPS and not a local or development host.
     */
    protected function isPublicUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return str_starts_with(strtolower($url), 'https://')
            && $host !== ''
            && ! in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '[::1]'], true)
            && ! str_ends_with($host, '.test')
            && ! str_ends_with($host, '.localhost')
            && ! str_ends_with($host, '.local');
    }

    protected function mapStatus(string $status): string
    {
        return match ($status) {
            'approved' => Payment::PAID_STATUS,
            'rejected', 'cancelled' => Payment::CANCELED_STATUS,
            'refunded', 'charged_back' => Payment::REVERSED_STATUS,
            default => Payment::PENDING_STATUS, // pending, in_process, authorized, in_mediation
        };
    }

    /**
     * Valida el header x-signature (HMAC-SHA256) si hay webhook_secret configurado.
     */
    protected function validSignature(Request $request, string $dataId): bool
    {
        if (empty($this->webhookSecret)) {
            return true;
        }

        $signature = (string) $request->header('x-signature');
        $requestId = (string) $request->header('x-request-id');

        $parts = [];
        foreach (explode(',', $signature) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            $parts[$key] = $value;
        }

        if (empty($parts['ts']) || empty($parts['v1'])) {
            return false;
        }

        $id = ctype_alnum($dataId) ? strtolower($dataId) : $dataId;
        $manifest = "id:{$id};";
        if ($requestId !== '') {
            $manifest .= "request-id:{$requestId};";
        }
        $manifest .= "ts:{$parts['ts']};";

        return hash_equals(hash_hmac('sha256', $manifest, $this->webhookSecret), $parts['v1']);
    }

    private function send(string $endpoint, array $params = [], string $method = 'GET'): array
    {
        $request = Http::acceptJson()->withToken($this->accessToken);

        $response = ($method === 'POST')
            ? $request->post($this->apiUrl.'/'.$endpoint, $params)
            : $request->get($this->apiUrl.'/'.$endpoint, $params);

        if (! $response->successful()) {
            throw new \Exception('MercadoPago API error: '.$response->body());
        }

        return $response->json();
    }

    // Hooks (para sobreescribir en la aplicación)
    public function afterSuccessful(Payment $payment)
    {
        return view('payment-gateways::mercadopago.successful', compact('payment'));
    }

    public function afterPending(Payment $payment)
    {
        return view('payment-gateways::mercadopago.pending', compact('payment'));
    }

    public function afterRejected(Payment $payment, string $error)
    {
        return view('payment-gateways::mercadopago.rejected', compact('payment', 'error'));
    }
}
