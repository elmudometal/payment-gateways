<?php

namespace Arca\PaymentGateways\Http\Controllers;

use Arca\PaymentGateways\Drivers\Niubiz\NiubizDriver;
use Arca\PaymentGateways\Drivers\Paypal\PaypalDriver;
use Arca\PaymentGateways\Drivers\Webpay\WebpayDriver;
use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\Events\PaymentApproved;
use Arca\PaymentGateways\Events\PaymentRejected;
use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\Support\PaymentManager;
use Arca\PaymentGateways\ValueObjects\ChargeResult;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CheckoutController extends Controller
{
    public function __construct(
        protected readonly PaymentManager $manager,
    ) {}

    public function show(Payment $payment)
    {
        if ($payment->isPaid()) {
            return redirect()->route('payments.success', $payment);
        }

        if ($payment->isFailed()) {
            return redirect()->route('payments.failed', $payment);
        }

        return view('payment-gateways::checkout', ['payment' => $payment]);
    }

    public function commit(Payment $payment, Request $request)
    {
        $driver = $this->manager->driver($payment->gateway);

        $result = match (true) {
            $driver instanceof WebpayDriver => $driver->commit($request, $payment),
            $driver instanceof NiubizDriver && $request->has('transactionToken') => $driver->authorize(
                $request->input('transactionToken'),
                $payment,
            ),
            $driver instanceof PaypalDriver && $payment->external_id => $driver->capture($payment),
            default => $driver->query($payment),
        };

        $this->applyResult($payment, $result);

        if ($result->isApproved()) {
            return redirect()->route('payments.success', $payment);
        }

        return redirect()->route('payments.failed', $payment);
    }

    public function success(Payment $payment)
    {
        return view('payment-gateways::success', ['payment' => $payment]);
    }

    public function failed(Payment $payment)
    {
        return view('payment-gateways::failed', ['payment' => $payment]);
    }

    protected function applyResult(Payment $payment, ChargeResult $result): void
    {
        $previousStatus = $payment->status;

        $payment->status = $result->status;
        $payment->external_id = $result->externalId ?? $payment->external_id;
        $payment->authorization_code = $result->authorizationCode ?? $payment->authorization_code;

        if ($result->raw) {
            $payment->metadata = array_merge($payment->metadata ?? [], ['voucher' => $result->raw]);
        }

        if ($result->isApproved() && ! $payment->paid_at) {
            $payment->paid_at = now();
        }

        $payment->save();

        if ($previousStatus !== $payment->status) {
            match ($payment->status) {
                PaymentStatus::Paid => PaymentApproved::dispatch($payment),
                PaymentStatus::Failed => PaymentRejected::dispatch($payment),
                default => null,
            };
        }
    }
}
