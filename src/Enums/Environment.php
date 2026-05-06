<?php

namespace Arca\PaymentGateways\Enums;

enum Environment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';

    public static function fromApp(): self
    {
        return app()->environment('production')
            ? self::Production
            : self::Sandbox;
    }
}
