<?php

namespace Arca\PaymentGateways\Contracts;

use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\ValueObjects\ChargeIntent;
use Arca\PaymentGateways\ValueObjects\ChargeResult;
use Illuminate\Http\Request;

interface PaymentGateway
{
    public function name(): string;

    /**
     * The reference sent to the gateway for this payment (order number, external reference).
     * PaymentService compares it with the reference the gateway reports back.
     */
    public function reference(Payment $payment): string;

    /**
     * Starts the checkout: returns a redirect URL, a view to render, or a final status.
     */
    public function charge(Payment $payment, ChargeIntent $intent): ChargeResult;

    /**
     * Handles the buyer returning to the commit URL. The request is never trusted on its own:
     * drivers confirm the status with the gateway API.
     */
    public function complete(Payment $payment, Request $request): ChargeResult;

    /**
     * Fetches the payment's current status from the gateway API.
     */
    public function query(Payment $payment): ChargeResult;
}
