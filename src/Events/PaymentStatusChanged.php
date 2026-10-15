<?php

namespace Arca\PaymentGateways\Events;

use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\Models\Payment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched on every status change, before the status-specific event (PaymentApproved, ...).
 */
class PaymentStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Payment $payment,
        public readonly PaymentStatus $from,
        public readonly PaymentStatus $to,
    ) {}
}
