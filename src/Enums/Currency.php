<?php

namespace Arca\PaymentGateways\Enums;

enum Currency: string
{
    case ARS = 'ARS';
    case BRL = 'BRL';
    case CLP = 'CLP';
    case COP = 'COP';
    case EUR = 'EUR';
    case MXN = 'MXN';
    case PEN = 'PEN';
    case PYG = 'PYG';
    case USD = 'USD';
    case UYU = 'UYU';

    /**
     * Number of decimals of the currency (ISO 4217 minor unit).
     */
    public function minorUnit(): int
    {
        return match ($this) {
            self::CLP, self::PYG => 0,
            default => 2,
        };
    }
}
