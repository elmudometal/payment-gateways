<?php

namespace Arca\PaymentGateways\Events;

use Arca\PaymentGateways\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentRefunded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Payment $payment,
    ) {}
}
