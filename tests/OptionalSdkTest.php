<?php

use Arca\PaymentGateways\Http\Controllers\Controller;
use Transbank\Webpay\WebpayPlus;

function gatewayController(): Controller
{
    return new class extends Controller
    {
        public function check(string $class): void
        {
            $this->requireSdk($class, 'vendor/sdk:^1.0', 'Ejemplo');
        }
    };
}

it('explains how to install a gateway sdk that is missing', function () {
    gatewayController()->check('Vendor\\Sdk\\Missing');
})->throws(RuntimeException::class, 'The Ejemplo gateway needs vendor/sdk:^1.0. Install it with: composer require vendor/sdk:^1.0');

it('does nothing when the gateway sdk is installed', function () {
    expect(fn () => gatewayController()->check(WebpayPlus::class))->not->toThrow(RuntimeException::class);
});
