<?php

namespace Arca\PaymentGateways\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Authorized = 'authorized';
    case Paid = 'paid';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case Reversed = 'reversed';

    public function label(): string
    {
        return __("payment-gateways::messages.status.{$this->value}");
    }

    /**
     * No further gateway status can change a final payment.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Refunded, self::Reversed], true);
    }

    /**
     * The buyer is done with the checkout: it is not started again for this payment.
     */
    public function isSettled(): bool
    {
        return $this !== self::Pending;
    }

    /**
     * Whether a gateway status may replace this one. Notifications can arrive out of order, so a
     * payment never moves back to pending and a paid payment only moves to refunded or reversed.
     * A failed payment may still become paid: some checkouts let the buyer retry after a rejection.
     */
    public function canTransitionTo(self $to): bool
    {
        if ($this === $to) {
            return false;
        }

        return match ($this) {
            self::Pending => true,
            self::Authorized => in_array($to, [self::Paid, self::Failed, self::Reversed], true),
            self::Failed => in_array($to, [self::Authorized, self::Paid], true),
            self::Paid => in_array($to, [self::Refunded, self::Reversed], true),
            self::Refunded, self::Reversed => false,
        };
    }
}
