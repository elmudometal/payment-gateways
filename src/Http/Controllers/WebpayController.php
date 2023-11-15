<?php

namespace Arca\PaymentGateways\Http\Controllers;

use App\Models\Orden;
use Illuminate\Http\Request;
use Transbank\Webpay\WebpayPlus;
use Transbank\Webpay\WebpayPlus\Transaction;

class WebpayController extends Controller
{
    public $paymentTypeCode = [
        'VD' => 'Venta Débito',
        'VN' => 'Venta Normal',
        'VC' => 'Venta en cuotas',
        'SI' => '3 cuotas sin interés',
        'S2' => '2 cuotas sin interés',
        'NC' => 'N Cuotas sin interés',
        'VP' => 'Venta Prepago',
    ];

    public $responseCode = [
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

    public function __construct()
    {
        /** Webpay api context **/
        if (app()->environment('production')) {
            WebpayPlus::configureForProduction(config('webpay.commerce_code'), config('webpay.private_key'));
        } else {
            WebpayPlus::configureForTesting();
        }
    }

    public function init($id)
    {
        $data['orden'] = Orden::with(['inscripcion'])->find($id);

        if (empty($data['orden']->token)) {
            $transaction = new Transaction;
            $data['result'] = $transaction->create(
                $data['orden']->id,
                session()->getId(),
                $data['orden']->monto,
                route('webpay.commit', $data['orden'])
            );

            /** Verificamos respuesta de inicio en webpay */
            if (! empty($data['result']->getToken())) {
                $data['orden']->token = $data['result']->getToken();
                $data['orden']->save();

                return view('webpay.init', $data);
            }

            return redirect()->route('webpay.init', $data['orden']);
        }

        return redirect()->route('webpay.commit', $data['orden']);
    }

    public function exito($id)
    {
        $data['orden'] = Orden::find($id);
        $data['promocion'] = $data['orden']->inscripcion->promocion;
        $data['paymentTypeCode'] = $this->paymentTypeCode;

        if ($data['orden']->estatus == Orden::ESTATUS_PAGADA) {
            $data['result'] = json_decode($data['orden']->comprobante);
        }

        return view('webpay.exito', $data);
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

        return view('webpay.rechazo', $data);
    }

    public function commit($id, Request $request)
    {
        $data['orden'] = Orden::find($id);

        if (! empty($request->token_ws)) {
            $result = (new Transaction)->commit($request->token_ws);

            /** Verificamos resultado de transacción */
            if ($result->isApproved()) {
                // Compra exitosa
                $data['orden']->comprobante = json_encode($result);
                $data['orden']->estatus = Orden::ESTATUS_PAGADA;
                $data['orden']->save();

                return redirect()->route('webpay.exito', $data['orden']);
            } else {
                $data['orden']->comprobante = json_encode($result);
                $data['orden']->estatus = Orden::ESTATUS_CANCELADA;
                $data['orden']->save();

                return redirect()->route('webpay.rechazo', $data['orden']);
            }
        } else {
            $result = (new Transaction)->status($data['orden']->token);
            $data['orden']->comprobante = json_encode($result);
            $data['orden']->estatus = Orden::ESTATUS_CANCELADA;
            $ruta = 'webpay.rechazo';
            if ($result->isApproved() && $result->status == 'AUTHORIZED') {
                $data['orden']->estatus = Orden::ESTATUS_PAGADA;
                $ruta = 'webpay.exito';
            }

            $data['orden']->save();

            return redirect()->route($ruta, $data['orden']);
        }
    }
}
