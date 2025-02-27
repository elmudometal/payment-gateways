<?php

namespace Arca\PaymentGateways\Http\Controllers;

use Arca\PaymentGateways\Models\Payment;
use Carbon\Carbon;
use DateTime;
use Dnetix\Redirection\PlacetoPay;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GetnetController extends Controller
{
    protected PlacetoPay $placetoPay;

    protected array $transaction;

    public function __construct()
    {
        if (app()->environment('production')) {
            $this->placetoPay = new PlacetoPay([
                'login' => config('payment-gateways.getnet.login'),
                'tranKey' => config('payment-gateways.getnet.tranKey'),
                'baseUrl' => 'https://checkout.getnet.cl',
                'type' => 'rest',
            ]);
        } else {
            $this->placetoPay = new PlacetoPay([
                'login' => '7ffbb7bf1f7361b1200b2e8d74e1d76f',
                'tranKey' => 'SnZP3D63n3I9dH9O',
                'baseUrl' => 'https://checkout.test.getnet.cl',
                'type' => 'rest',
            ]);
        }
    }

    public function init(Payment $payment)
    {
        if (empty($payment->token)) {
            $this->transaction($payment);
            $response = $this->placetoPay->request($this->transaction);

            try {
                if ($response->isSuccessful()) {
                    $payment->token = $response->requestId();
                    $payment->save();

                    return redirect()->away($response->processUrl());
                }
            } catch (\Exception $e) {
                \Log::error('Error en la solicitud de pago: '.$e->getMessage());
            }

            return redirect()->route('getnet.init', $payment->uuid);
        }

        return redirect()->route('getnet.commit', $payment->uuid);
    }

    public function commit(Payment $payment, Request $request)
    {
        $this->beforeCommit($payment);
        $response = $this->placetoPay->query((int) $payment->token);
        $result = $response->toArray();

        if ($response->isSuccessful()) {

            /** Verificamos resultado de transacción */
            if ($response->status()->isApproved()) {
                // Compra successful
                $payment->voucher = $result;
                $payment->status = Payment::PAID_STATUS;
                $payment->authorization_code = $result['payment'][0]['authorization'];
                $payment->save();

                $this->afterCommit($payment);

                return redirect()->route('getnet.successful', $payment);
            } else {
                $payment->voucher = $result;
                $payment->status = Payment::CANCELED_STATUS;
                $payment->save();

                return redirect()->route('getnet.rejected', $payment);
            }
        } else {
            $payment->voucher = $result;
            $payment->status = Payment::CANCELED_STATUS;
            $ruta = 'getnet.rejected';
            if ($response->status()->isApproved()) {
                $payment->status = Payment::PAID_STATUS;
                $payment->authorization_code = $result['payment'][0]['authorization'];
                $ruta = 'getnet.successful';
            }

            $payment->save();

            return redirect()->route($ruta, $payment->uuid);
        }
    }

    public function successful(Payment $payment)
    {
        if ($payment->status != Payment::PAID_STATUS) {
            return redirect()->route('getnet.rejected');
        }

        return $this->afterSuccessful($payment);
    }

    public function rejected(Payment $payment)
    {
        $error = $payment->voucher['status']['message'];
        if (array_key_exists('payment', $payment->voucher)) {
            $error = $payment->voucher['payment'][0]['status']['message'];
        }

        return $this->afterRejected($payment, $error);
    }

    protected function addItems(Payment $payment): void
    {
        $this->transaction['payment'] = [
            'reference' => $payment->id,
            'description' => Str::limit($payment->comments, 240),
            'amount' => [
                'currency' => 'CLP',
                'total' => $payment->amount,
            ],
            'allowPartial' => false,
        ];
    }

    protected function transaction(Payment $payment)
    {
        $this->transaction = [
            'locale' => 'es_CL',
            'expiration' => Carbon::now()->addHour()->format(DateTime::ATOM),
            'ipAddress' => request()->ip(),
            'userAgent' => 'Arca/Site',
            'returnUrl' => route('getnet.commit', $payment->uuid),
            'cancelUrl' => route('getnet.commit', $payment->uuid),
        ];

        $this->addItems($payment);

        return $this->transaction;
    }

    public function beforeCommit(Payment $payment) {}

    public function afterCommit(Payment $payment) {}

    public function afterSuccessful(Payment $payment)
    {
        return view('payment-gateways::getnet.successful', ['payment' => $payment]);
    }

    public function afterRejected(Payment $payment, string $error)
    {
        return view('payment-gateways::getnet.rejected', ['payment' => $payment, 'error' => $error]);
    }
}
