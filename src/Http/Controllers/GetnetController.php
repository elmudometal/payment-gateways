<?php

namespace Arca\PaymentGateways\Http\Controllers;

use Arca\PaymentGateways\Models\Payment;
use Carbon\Carbon;
use DateTime;
use Dnetix\Redirection\PlacetoPay;
use Illuminate\Http\Request;

class GetnetController extends Controller
{
    protected PlacetoPay $placetoPay;

    protected array $transaction;

    public function __construct()
    {
        $this->placetoPay = new PlacetoPay([
            'login' => config('payment-gateways.getnet.login'),
            'tranKey' => config('payment-gateways.getnet.tranKey'),
            'baseUrl' => config('payment-gateways.getnet.baseUrl'),
            'type' => 'rest',
        ]);
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

                    return view('payment-gateways::getnet.init', ['payment' => $payment, 'url' => $response->processUrl()]);
                }
            } catch (\Exception $e) {
                \Log::error('Error en la solicitud de pago: '.$e->getMessage());
            }

            return redirect()->route('getnet.init', $payment->uuid);
        }

        return redirect()->route('getnet.commit', $payment);
    }

    public function successful(Payment $payment)
    {
        if ($payment->status == Payment::ESTATUS_CANCELADA) {
            return redirect()->route('getnet.rejected');
        }

        return view('payment-gateways::getnet.successful', ['payment' => $payment]);
    }

    public function rejected(Payment $payment)
    {
        $error = $payment->voucher['status']['message'];
        if (array_key_exists('payment', $payment->voucher)) {
            $error = $payment->voucher['payment'][0]['status']['message'];
        }

        return view('payment-gateways::getnet.rejected', ['payment' => $payment, 'error' => $error]);
    }

    public function commit(Payment $payment, Request $request)
    {
        $response = $this->placetoPay->query($payment->token);
        $result = $response->toArray();

        if ($response->isSuccessful()) {

            /** Verificamos resultado de transacción */
            if ($response->status()->isApproved()) {
                // Compra successful
                $payment->voucher = ($result);
                $payment->status = Payment::ESTATUS_PAGADA;
                $payment->save();

                return redirect()->route('getnet.successful', $payment);
            } else {
                $payment->voucher = ($result);
                $payment->status = Payment::ESTATUS_CANCELADA;
                $payment->save();

                return redirect()->route('getnet.rejected', $payment);
            }
        } else {
            $payment->voucher = ($result);
            $payment->status = Payment::ESTATUS_CANCELADA;
            $ruta = 'getnet.rejected';
            if ($response->status()->isApproved()) {
                $payment->status = Payment::ESTATUS_PAGADA;
                $ruta = 'getnet.successful';
            }

            $payment->save();

            return redirect()->route($ruta, $payment->uuid);
        }
    }

    protected function addItems(Payment $payment): void
    {
        $this->transaction['payment'] = [
            'reference' => $payment->id,
            'description' => $payment->comments,
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
            'returnUrl' => route('getnet.commit', $payment->id),
            'cancelUrl' => route('getnet.commit', $payment->id),
        ];

        $this->addItems($payment);

        return $this->transaction;
    }
}
