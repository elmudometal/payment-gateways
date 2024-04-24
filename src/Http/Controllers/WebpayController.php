<?php

namespace Arca\PaymentGateways\Http\Controllers;

use Arca\PaymentGateways\Enums\TransactionStatus;
use Arca\PaymentGateways\Models\Payment;
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
            WebpayPlus::configureForProduction(config('payment-gateways.webpay.commerce_code'), config('payment-gateways.webpay.commerce_api_key'));
        } else {
            WebpayPlus::configureForTesting();
        }
    }

    public function init(Payment $payment)
    {
        try {
            if (empty($payment->token)) {
                $transaction = new Transaction;
                $response = $transaction->create(
                    (string) $payment->id,
                    session()->getId(),
                    $payment->amount,
                    route('webpay.commit', $payment->uuid)
                );

                /** Verificamos respuesta de inicio en webpay */
                if (! empty($response->getToken())) {
                    $payment->token = $response->getToken();
                    $payment->save();

                    return redirect()->away($response->getUrl().'?token_ws='.$response->getToken());
                }

                return redirect()->route('webpay.init', $payment->uuid);
            }

            return redirect()->route('webpay.commit', $payment->uuid);
        } catch (\Exception $e) {
            \Log::error('Error en la solicitud de pago: '.$e->getMessage());
        }
    }

    public function commit(Payment $payment, Request $request)
    {
        $transactionStatus = $this->getStatusTransaction($request);

        if ($transactionStatus == TransactionStatus::NORMAL_PAYMENT_FLOW) {

            $response = (new Transaction)->commit($request->token_ws);

            /** Verificamos resultado de transacción */
            if ($response->isApproved()) {
                // Compra exitosa
                $payment->voucher = json_decode(json_encode($response), true);
                $payment->status = Payment::PAID_STATUS;
                $payment->authorization_code = $response->authorizationCode;
                $payment->save();

                return redirect()->route('webpay.successful', $payment->uuid);
            } else {
                $payment->voucher = json_decode(json_encode($response), true);
                $payment->status = Payment::CANCELED_STATUS;
                $payment->voucher = $payment->voucher + ['message' => $this->responseCode[$payment->voucher['responseCode']] ?? ''];
                $payment->save();

                return redirect()->route('webpay.rejected', $payment->uuid);
            }
        } else {
            $response = (new Transaction)->status($payment->token);
            $payment->voucher = json_decode(json_encode($response), true);
            $payment->status = Payment::CANCELED_STATUS;
            $payment->voucher = $payment->voucher + ['message' => $transactionStatus->getMessage()];
            $route = 'webpay.rejected';
            if ($response->isApproved()) {
                $payment->status = Payment::PAID_STATUS;
                $payment->authorization_code = $response->authorizationCode;
                $route = 'webpay.successful';
            }

            $payment->save();

            return redirect()->route($route, $payment->uuid);
        }
    }

    public function getStatusTransaction(Request $request): TransactionStatus
    {
        $tokenWs = $request->input('token_ws');
        $tbkToken = $request->input('TBK_TOKEN');
        $ordenCompra = $request->input('TBK_ORDEN_COMPRA');
        $idSesion = $request->input('TBK_ID_SESION');

        if ($tbkToken && $ordenCompra && $idSesion && ! $tokenWs) {
            return TransactionStatus::USER_ABORTED;
        }

        if ($tokenWs && $ordenCompra && $idSesion && $tbkToken) {
            return TransactionStatus::ERROR_OCCURRED;
        }

        if ($ordenCompra && $idSesion && ! $tokenWs && ! $tbkToken) {
            return TransactionStatus::USER_REDIRECTED_IDLE;
        }

        if ($tokenWs && ! $ordenCompra && ! $idSesion && ! $tbkToken) {
            return TransactionStatus::NORMAL_PAYMENT_FLOW;
        }

        return TransactionStatus::UNKNOWN_STATUS;
    }

    public function successful(Payment $payment)
    {
        return $this->afterSuccessful($payment);
    }

    public function rejected(Payment $payment)
    {
        return $this->afterRejected($payment);
    }

    public function beforeCommit(Payment $payment)
    {
    }

    public function afterCommit(Payment $payment)
    {
    }

    public function afterSuccessful(Payment $payment)
    {
        $data['paymentTypeCode'] = $this->paymentTypeCode;

        return view('payment-gateways::webpay.successful', ['payment' => $payment, 'paymentTypeCode' => $this->paymentTypeCode]);
    }

    public function afterRejected(Payment $payment)
    {
        return view('payment-gateways::webpay.rejected', ['payment' => $payment]);
    }
}
