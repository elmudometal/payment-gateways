<?php

namespace Arca\PaymentGateways\Http\Controllers;

use App\Models\Orden;
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
        $this->placetoPay = new placetopay([
            'login' => '7ffbb7bf1f7361b1200b2e8d74e1d76f',
            'tranKey' => 'SnZP3D63n3I9dH9O',
            'baseUrl' => 'https://checkout.test.getnet.cl',
            'type' => 'rest',
        ]);
    }

    public function init($id)
    {
        $orden = Orden::with(['inscripcion'])->find($id);

        if (empty($orden->token)) {
            $this->transaction($orden);

            $response = $this->placetoPay->request($this->transaction);

            /** Verificamos respuesta de inicio en Getnet */
            if ($response->isSuccessful()) {
                $orden->token = $response->requestId();
                $orden->save();

                return view('getnet.init', ['orden' => $orden, 'url' => $response->processUrl()]);
            }

            return redirect()->route('getnet.init', $orden);
        }

        return redirect()->route('getnet.commit', $orden);
    }

    public function exito($id)
    {
        $data['orden'] = Orden::find($id);
        $data['promocion'] = $data['orden']->inscripcion->promocion;

        if ($data['orden']->estatus == Orden::ESTATUS_PAGADA) {
            $data['result'] = json_decode($data['orden']->comprobante);
        }
        dd($data);

        return view('getnet.exito', $data);
    }

    public function rechazo($id)
    {
        $data['orden'] = Orden::find($id);
        $data['promocion'] = $data['orden']->inscripcion->promocion;
        $result = json_decode($data['orden']->comprobante);
        $data['result'] = 'Error Inesperado durante el proceso de WebPay';
        if (empty($result) || $result->status == 'INITIALIZED') {
            $data['result'] = 'Error Inesperado durante el proceso de WebPay (Pago Anulado Por el Usuario.)';
        } elseif (empty($result) || $result->status == 'FAILED') {
            $data['result'] = $this->responseCode[$result->responseCode];
        }

        return view('getnet.rechazo', $data);
    }

    public function commit($id, Request $request)
    {
        $data['orden'] = Orden::find($id);

        $response = $this->placetoPay->query($data['orden']->token);
        $result = $response->toArray();

        if ($response->isSuccessful()) {

            /** Verificamos resultado de transacción */
            if ($response->status()->isApproved()) {
                // Compra exitosa
                $data['orden']->comprobante = json_encode($result);
                $data['orden']->estatus = Orden::ESTATUS_PAGADA;
                $data['orden']->save();

                return redirect()->route('getnet.exito', $data['orden']);
            } else {
                $data['orden']->comprobante = json_encode($result);
                $data['orden']->estatus = Orden::ESTATUS_CANCELADA;
                $data['orden']->save();

                return redirect()->route('getnet.rechazo', $data['orden']);
            }
        } else {
            $data['orden']->comprobante = json_encode($result);
            $data['orden']->estatus = Orden::ESTATUS_CANCELADA;
            $ruta = 'getnet.rechazo';
            if ($result->isApproved() && $result->status == 'AUTHORIZED') {
                $data['orden']->estatus = Orden::ESTATUS_PAGADA;
                $ruta = 'getnet.exito';
            }

            $data['orden']->save();

            return redirect()->route($ruta, $data['orden']);
        }
    }

    public function addItems(Orden $orden): void
    {
        $this->transaction['payment'] = [
            'reference' => $orden->id,
            'description' => 'Detalle de este pago.',
            'amount' => [
                'currency' => 'CLP',
                'total' => 1076.3,
            ],
            'allowPartial' => false,
        ];
    }

    public function transaction(Orden $orden)
    {
        $this->transaction = [
            'locale' => 'es_CL',
            'expiration' => Carbon::now()->addHour()->format(DateTime::ATOM),
            'ipAddress' => '127.0.0.1',
            'userAgent' => 'Arca/Site',
            'returnUrl' => route('getnet.commit', $orden->id),
            'cancelUrl' => route('getnet.commit', $orden->id),
        ];

        $this->addItems($orden);

        return $this->transaction;
    }
}
