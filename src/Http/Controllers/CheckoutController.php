<?php

namespace Arca\PaymentGateways\Http\Controllers;

use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\Exceptions\GatewayException;
use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\Services\PaymentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class CheckoutController extends Controller
{
    public function __construct(
        protected readonly PaymentService $payments,
    ) {}

    /**
     * Starts the checkout: redirects the buyer to the gateway or renders its embedded form.
     */
    public function show(Payment $payment): RedirectResponse|View|Response
    {
        if (! $payment->isPending()) {
            return $this->redirectToResult($payment);
        }

        if ($payment->transaction_id !== null) {
            // The buyer already paid with a method that settles later (cash, transfer).
            return $this->redirectToResult($payment);
        }

        try {
            $result = $this->payments->charge($payment);
        } catch (GatewayException $e) {
            return $this->error($payment, $e);
        }

        if ($result->requiresView()) {
            return view((string) $result->view, $result->viewData + ['payment' => $payment]);
        }

        if ($result->requiresRedirect()) {
            return redirect()->away((string) $result->redirectUrl);
        }

        return $this->redirectToResult($payment);
    }

    /**
     * The buyer is back from the gateway.
     */
    public function commit(Payment $payment, Request $request): RedirectResponse|Response
    {
        try {
            $this->payments->complete($payment, $request);
        } catch (GatewayException $e) {
            return $this->error($payment, $e);
        }

        return $this->redirectToResult($payment);
    }

    public function success(Payment $payment): RedirectResponse|View
    {
        if (! in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::Authorized], true)) {
            return $this->redirectToResult($payment);
        }

        return view('payment-gateways::success', ['payment' => $payment]);
    }

    public function pending(Payment $payment): RedirectResponse|View
    {
        if (! $payment->isPending()) {
            return $this->redirectToResult($payment);
        }

        return view('payment-gateways::pending', ['payment' => $payment]);
    }

    public function failed(Payment $payment): RedirectResponse|View
    {
        if (! in_array($payment->status, [PaymentStatus::Failed, PaymentStatus::Refunded, PaymentStatus::Reversed], true)) {
            return $this->redirectToResult($payment);
        }

        return view('payment-gateways::failed', ['payment' => $payment, 'error' => null]);
    }

    protected function redirectToResult(Payment $payment): RedirectResponse
    {
        $page = match ($payment->status) {
            PaymentStatus::Paid, PaymentStatus::Authorized => 'success',
            PaymentStatus::Pending => 'pending',
            default => 'failed',
        };

        $route = config("payment-gateways.redirects.{$page}") ?: "payments.{$page}";

        return redirect()->route($route, $payment);
    }

    /**
     * The gateway could not be reached or answered something we refuse. The payment stays as it
     * was, so the buyer can retry.
     */
    protected function error(Payment $payment, GatewayException $e): Response
    {
        Log::channel(config('payment-gateways.log_channel'))->error(
            "[payment-gateways:{$payment->gateway}] Checkout error for payment [{$payment->uuid}]: {$e->getMessage()}",
            $e->context,
        );

        return response()->view('payment-gateways::failed', [
            'payment' => $payment,
            'error' => __('payment-gateways::messages.gateway_error'),
        ], 502);
    }
}
