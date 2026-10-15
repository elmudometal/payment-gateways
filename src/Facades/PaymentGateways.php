<?php

namespace Arca\PaymentGateways\Facades;

use Arca\PaymentGateways\Contracts\PaymentGateway;
use Arca\PaymentGateways\Support\PaymentManager;
use Arca\PaymentGateways\Support\Testing\FakeDriver;
use Illuminate\Support\Facades\Facade;

/**
 * @method static PaymentGateway driver(?string $driver = null)
 * @method static FakeDriver fake(?string $driver = null)
 * @method static PaymentManager extend(string $driver, \Closure $callback)
 *
 * @see PaymentManager
 */
class PaymentGateways extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PaymentManager::class;
    }
}
