<?php

namespace Arca\PaymentGateways\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use RuntimeException;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * Gateway SDKs are optional dependencies: fail with an actionable message when the gateway is
     * used without its SDK installed.
     */
    protected function requireSdk(string $class, string $package, string $gateway): void
    {
        if (! class_exists($class)) {
            throw new RuntimeException("The {$gateway} gateway needs {$package}. Install it with: composer require {$package}");
        }
    }
}
