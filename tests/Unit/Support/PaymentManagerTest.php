<?php

use Arca\PaymentGateways\Contracts\PaymentGateway;
use Arca\PaymentGateways\Drivers\MercadoPago\MercadoPagoDriver;
use Arca\PaymentGateways\Drivers\Webpay\WebpayDriver;
use Arca\PaymentGateways\Exceptions\GatewayConfigurationException;
use Arca\PaymentGateways\Support\PaymentManager;
use Arca\PaymentGateways\Support\Testing\FakeDriver;

it('resolves the default gateway', function () {
    expect(app(PaymentManager::class)->driver())->toBeInstanceOf(WebpayDriver::class);
});

it('throws for a gateway missing from the config', function () {
    app(PaymentManager::class)->driver('unknown');
})->throws(GatewayConfigurationException::class, 'Gateway [unknown] is not configured.');

it('throws for an unknown driver', function () {
    config()->set('payment-gateways.gateways.shop', ['driver' => 'nope']);

    app(PaymentManager::class)->driver('shop');
})->throws(GatewayConfigurationException::class, 'Driver [nope]');

it('serves several accounts with one driver', function () {
    config()->set('payment-gateways.gateways.mp_peru', ['driver' => 'mercadopago', 'access_token' => 'PE-token']);

    expect(app(PaymentManager::class)->driver('mp_peru'))->toBeInstanceOf(MercadoPagoDriver::class);
});

it('passes the gateway config to custom drivers', function () {
    config()->set('payment-gateways.gateways.custom', ['driver' => 'custom', 'key' => 'secret']);
    $received = null;

    app(PaymentManager::class)->extend('custom', function ($app, array $config) use (&$received) {
        $received = $config;

        return new FakeDriver('custom');
    });

    expect(app(PaymentManager::class)->driver('custom'))->toBeInstanceOf(PaymentGateway::class)
        ->and($received['key'])->toBe('secret');
});

it('replaces an already resolved driver with a fake', function () {
    $manager = app(PaymentManager::class);
    $manager->driver('webpay');

    $fake = $manager->fake('webpay');

    expect($manager->driver('webpay'))->toBe($fake)
        ->and($fake->name())->toBe('webpay');
});
