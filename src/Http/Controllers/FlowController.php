<?php

namespace Arca\PaymentGateways\Http\Controllers;

use Arca\PaymentGateways\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FlowController extends Controller
{
    protected string $apiKey;

    protected string $secretKey;

    protected string $apiUrl;

    public function __construct()
    {
        $this->apiUrl = 'https://sandbox.flow.cl/api';
        $this->apiKey = config('payment-gateways.flow.api_key');
        $this->secretKey = config('payment-gateways.flow.secret_key');

        if (app()->environment('production')) {
            $this->apiUrl = 'https://www.flow.cl/api';
        }
    }

    public function init(Payment $payment)
    {
        if ($payment->status === Payment::PAID_STATUS) {
            return redirect()->route('flow.successful', $payment->uuid);
        } elseif ($payment->status === Payment::CANCELED_STATUS) {
            return redirect()->route('flow.rejected', $payment->uuid);
        }

        $params = [
            'commerceOrder' => $payment->uuid,
            'subject' => $payment->comments,
            'currency' => 'CLP',
            'amount' => $payment->amount,
            'email' => $payment->model?->email,
            'paymentMethod' => 9,
            'urlConfirmation' => route('flow.confirm', $payment->uuid),
            'urlReturn' => route('flow.commit', $payment->uuid),
        ];

        try {
            $response = $this->send('payment/create', $params, 'POST');
            $payment->token = $response['token'];
            $payment->save();

            return redirect()->away($response['url'].'?token='.$response['token']);
        } catch (\Exception $e) {
            Log::error('Flow init error: '.$e->getMessage());

            return redirect()->route('flow.rejected', $payment->uuid);
        }
    }

    public function confirm(Payment $payment, Request $request)
    {
        try {
            $params = ['token' => $request->token];
            $response = $this->send('payment/getStatus', $params);

            $payment->voucher = $response;
            $payment->status = ($response['status'] == 2) ? Payment::PAID_STATUS : Payment::CANCELED_STATUS;
            $payment->save();

            return response()->json(['message' => 'success']);
        } catch (\Exception $e) {
            Log::error('Flow confirm error: '.$e->getMessage());

            return response()->json(['message' => 'error'], 500);
        }
    }

    public function commit(Payment $payment, Request $request)
    {
        if (empty($payment->voucher) || $payment->status == Payment::PENDING_STATUS) {
            $params = ['token' => $request->token];
            $response = $this->send('payment/getStatus', $params);

            $payment->voucher = $response;
            $payment->status = ($response['status'] == 2) ? Payment::PAID_STATUS : Payment::CANCELED_STATUS;
            $payment->save();
        }

        if ($payment->status === Payment::PAID_STATUS) {
            return redirect()->route('flow.successful', $payment->uuid);
        }

        return redirect()->route('flow.rejected', $payment->uuid);
    }

    public function successful(Payment $payment)
    {
        return $this->afterSuccessful($payment);
    }

    public function rejected(Payment $payment)
    {
        $error = $payment->voucher['status'] ?? 'Error desconocido';

        return $this->afterRejected($payment, $error);
    }

    // Métodos auxiliares
    private function send($endpoint, $params, $method = 'GET')
    {
        $url = $this->apiUrl.'/'.$endpoint;
        $params = array_merge(['apiKey' => $this->apiKey], $params);
        $params['s'] = $this->sign($params);

        $response = ($method === 'POST')
            ? Http::asForm()->post($url, $params)
            : Http::get($url, $params);

        if (! $response->successful()) {
            throw new \Exception('Flow API error: '.$response->body());
        }

        return $response->json();
    }

    private function sign($params)
    {
        ksort($params);
        $toSign = implode('', array_map(fn ($k, $v) => $k.$v, array_keys($params), $params));

        return hash_hmac('sha256', $toSign, $this->secretKey);
    }

    // Hooks (vacíos para sobreescribir en la aplicación)
    public function afterSuccessful(Payment $payment)
    {
        return view('payment-gateways::flow.successful', compact('payment'));
    }

    public function afterRejected(Payment $payment, string $error)
    {
        return view('payment-gateways::flow.rejected', compact('payment', 'error'));
    }
}
