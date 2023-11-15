<?php

namespace Arca\PaymentGateways\Http\Controllers;

use App\Models\Orden;
use Illuminate\Http\Request;
use PayPalCheckoutSdk\Core\PayPalHttpClient;
use PayPalCheckoutSdk\Core\ProductionEnvironment;
use PayPalCheckoutSdk\Core\SandboxEnvironment;
use PayPalCheckoutSdk\Orders\OrdersCaptureRequest;
use PayPalCheckoutSdk\Orders\OrdersCreateRequest;
use PayPalHttp\HttpException;

/** All Paypal Details class **/
class PaypalController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        /** PayPal api context **/
        $this->paypal_conf = \Config::get('paypal');
        if (env('APP_ENV') == 'local') {
            $environment = new SandboxEnvironment($this->paypal_conf['client_id'], $this->paypal_conf['secret']);
        } else {
            $environment = new ProductionEnvironment($this->paypal_conf['client_id'], $this->paypal_conf['secret']);
        }
        $this->paypal = new PayPalHttpClient($environment);
    }

    public function init(Request $request)
    {
        $data['CLIENT_ID'] = $this->paypal_conf['client_id'];
        $data['orden'] = Orden::find($request->orden_id);
        $data['curso'] = $data['orden']->inscripcion->curso;

        if ($data['orden']->estatus == Orden::ESTATUS_PAGADA) {
            return self::exito($data['orden']->id);
        } elseif ($data['orden']->estatus == Orden::ESTATUS_CANCELADA) {
            return self::rechazo($data['orden']->id);
        }

        return view('paypal.init', $data);
    }

    public function create(Request $request, $id)
    {
        $data['CLIENT_ID'] = $this->paypal_conf['client_id'];
        $data['orden'] = Orden::find($id);
        $data['curso'] = $data['orden']->inscripcion->curso;

        $request = new OrdersCreateRequest();
        $request->prefer('return=representation');

        $request->body = [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'reference_id' => $data['orden']->id,
                    'amount' => [
                        'value' => $data['orden']->monto,
                        'currency_code' => 'USD',
                    ],
                ],
            ],
            'application_context' => [
                'cancel_url' => route('paypal.rechazo', $data['orden']->id),
                'return_url' => route('paypal.exito', $data['orden']->id),
            ],
        ];

        try {
            // Call API with your client and get a response for your call
            $response = $this->paypal->execute($request);
            $data['orden']->comprobante = json_encode($response);
            $data['orden']->save();

            return response()->json($response->result);
            // If call returns body in response, you can get the deserialized version from the result attribute of the response
        } catch (HttpException $ex) {
            return response()->json([
                'status' => $ex->statusCode,
                'message' => json_decode($ex->getMessage(), true),
            ]);
        }
    }

    public function capture($id, $OrderID)
    {
        $request = new OrdersCaptureRequest($OrderID);
        $orden = Orden::find($id);
        // To toggle printing the whole response body comment/uncomment below line
        try {
            $response = $this->paypal->execute($request);
            $orden->comprobante = json_encode($response);
            $orden->estatus = Orden::ESTATUS_PAGADA;
            $orden->save();

            return response()->json($response->result);
        } catch (HttpException $ex) {
            $orden->estatus = Orden::ESTATUS_CANCELADA;
            $orden->comprobante = json_encode([
                'status' => $ex->statusCode,
                'object' => json_decode($ex->getMessage(), true),
            ]);
            $orden->save();

            return response()->json([
                'status' => $ex->statusCode,
                'object' => json_decode($ex->getMessage(), true),
            ]);
        }
    }

    public function rechazo($orden)
    {
        $data['orden'] = Orden::find($orden);
        $data['curso'] = $data['orden']->inscripcion->curso;
        $data['result'] = json_decode($data['orden']->comprobante);

        return view('paypal.rechazo', $data);
    }

    public function exito($orden)
    {
        $data['orden'] = Orden::find($orden);
        $data['curso'] = $data['orden']->inscripcion->curso;
        $data['result'] = optional(json_decode($data['orden']->comprobante))->result;

        return view('paypal.exito', $data);
    }
}
