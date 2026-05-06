<?php

namespace Arca\PaymentGateways\Contracts;

use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\ValueObjects\ChargeIntent;
use Arca\PaymentGateways\ValueObjects\ChargeResult;

interface PaymentGateway
{
    public function name(): string;

    public function charge(Payment $payment, ChargeIntent $intent): ChargeResult;

    public function query(Payment $payment): ChargeResult;
}
