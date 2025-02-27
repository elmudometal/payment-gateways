<?php

namespace Arca\PaymentGateways\Http\Controllers;

use Arca\PaymentGateways\Models\Payment;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

/** All Paypal Details class **/
class PaypalController extends Controller
{
    private $baseURL;

    private $clientId;

    private $clientSecret;

    public function __construct()
    {
        $this->baseURL = 'https://api-m.sandbox.paypal.com';
        if (app()->environment('production')) {
            $this->baseURL = 'https://api-m.paypal.com';
        }
        $this->clientId = config('payment-gateways.paypal.client_id');
        $this->clientSecret = config('payment-gateways.paypal.client_secret');
    }

    public function init(Payment $payment)
    {
        if ($payment->status == Payment::PAID_STATUS) {
            return redirect()->route('paypal.successful', $payment->uuid);
        } elseif ($payment->status == Payment::CANCELED_STATUS) {
            return redirect()->route('paypal.rejected', $payment->uuid);
        }

        return view('payment-gateways::paypal.init', ['payment' => $payment, 'clientId' => $this->clientId]);
    }

    public function create(Payment $payment)
    {
        try {
            $accessToken = $this->generateAccessToken();

            $response = Http::acceptJson()
                ->withToken($accessToken)
                ->post($this->baseURL.'/v2/checkout/orders', [
                    'intent' => 'CAPTURE',
                    'purchase_units' => [
                        [
                            'amount' => [
                                'currency_code' => 'USD',
                                'value' => $payment->amount,
                            ],
                        ],
                    ],
                ]);

            $data = $response->json();

            return response()->json($data, $response->status());
        } catch (\Exception $e) {
            \Log::error('Failed to create order: '.$e->getMessage());

            return response()->json(['error' => 'Failed to create order.'], 500);
        }
    }

    public function commit(Payment $payment, $orderID)
    {
        try {
            $accessToken = $this->generateAccessToken();
            $this->beforeCommit($payment);
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->withToken($accessToken)
                ->send('post', $this->baseURL."/v2/checkout/orders/{$orderID}/capture");

            $data = $response->json();
            $dataInfo = Arr::dot($data);
            $payment->voucher = $data;

            if ($response->successful() && in_array($dataInfo['status'], ['COMPLETED', 'APPROVED'])) {
                $payment->status = Payment::PAID_STATUS;
                $payment->authorization_code = $dataInfo['purchase_units.0.payments.captures.0.id'];
            } else {
                $payment->status = Payment::CANCELED_STATUS;
            }

            $payment->save();

            $this->afterCommit($payment);

            return response()->json($data, $response->status());
        } catch (\Exception $e) {
            \Log::error('Failed to capture order: '.$e->getMessage());

            return response()->json(['error' => 'Failed to capture order.'], 500);
        }
    }

    private function generateAccessToken()
    {
        try {
            $response = Http::asForm()
                ->withBasicAuth($this->clientId, $this->clientSecret)
                ->post($this->baseURL.'/v1/oauth2/token', [
                    'grant_type' => 'client_credentials',
                ]);

            $data = $response->json();

            return $data['access_token'];
        } catch (\Exception $e) {
            \Log::error('Error al conectar a paypal: '.$e->getMessage());

            return false;
        }
    }

    public function successful(Payment $payment)
    {
        if ($payment->status != Payment::PAID_STATUS) {
            return redirect()->route('paypal.rejected');
        }

        return $this->afterSuccessful($payment);
    }

    public function rejected(Payment $payment)
    {
        $error = 'Error inesperado';
        if (array_key_exists('details', $payment->voucher)) {
            $error = $payment->voucher['details'][0]['description'];
        }

        return $this->afterRejected($payment, $error);
    }

    public function beforeCommit(Payment $payment) {}

    public function afterCommit(Payment $payment) {}

    public function afterSuccessful(Payment $payment)
    {
        return view('payment-gateways::paypal.successful', ['payment' => $payment]);
    }

    public function afterRejected(Payment $payment, string $error)
    {
        return view('payment-gateways::paypal.rejected', ['payment' => $payment, 'error' => $error]);
    }
}
