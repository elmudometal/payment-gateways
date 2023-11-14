<?php

namespace Arca\PaymentGateways\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Arca\PaymentGateways\PaymentGateways
 */
class PaymentGateways extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Arca\PaymentGateways\PaymentGateways::class;
    }
}
