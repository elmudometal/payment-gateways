<?php

namespace Arca\PaymentGateways\Contracts;

use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\ValueObjects\Money;
use Arca\PaymentGateways\ValueObjects\RefundResult;

interface SupportsRefunds
{
    public function refund(Payment $payment, ?Money $amount = null): RefundResult;
}
