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
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Authorized => 'Autorizado',
            self::Paid => 'Pagado',
            self::Failed => 'Rechazado',
            self::Refunded => 'Reembolsado',
            self::Reversed => 'Reversado',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Paid, self::Failed, self::Refunded, self::Reversed]);
    }
}
