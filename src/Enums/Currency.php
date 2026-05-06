<?php

namespace Arca\PaymentGateways\Enums;

enum Currency: string
{
    case CLP = 'CLP';
    case USD = 'USD';
    case PEN = 'PEN';
    case BRL = 'BRL';
    case ARS = 'ARS';
    case MXN = 'MXN';
    case COP = 'COP';
    case EUR = 'EUR';

    public function minorUnit(): int
    {
        return match ($this) {
            self::CLP => 0,
            default => 2,
        };
    }
}
