<?php

namespace Arca\PaymentGateways\ValueObjects;

use Arca\PaymentGateways\Models\Payment;

/**
 * A verified notification: the local payment it refers to and the status the gateway reports.
 */
final readonly class WebhookResult
{
    public function __construct(
        public Payment $payment,
        public ChargeResult $result,
    ) {}
}
