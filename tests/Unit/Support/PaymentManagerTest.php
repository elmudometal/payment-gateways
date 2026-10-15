<?php

use Arca\PaymentGateways\Contracts\PaymentGateway;
use Arca\PaymentGateways\Exceptions\GatewayConfigurationException;
use Arca\PaymentGateways\Support\PaymentManager;
use Arca\PaymentGateways\Support\Testing\FakeDriver;

it('resolves the default driver', function () {
    $manager = app(PaymentManager::class);

    expect($manager->getDefaultDriver())->toBe('webpay');
});

it('throws on unconfigured gateway', function () {
    config()->set('payment-gateways.gateways.unknown', null);

    $manager = app(PaymentManager::class);
    $manager->driver('unknown');
})->throws(GatewayConfigurationException::class);

it('allows extending with custom drivers', function () {
    $manager = app(PaymentManager::class);

    $custom = new class implements PaymentGateway
    {
        public function name(): string
        {
            return 'custom';
        }

        public function charge($payment, $intent): \Arca\PaymentGateways\ValueObjects\ChargeResult
        {
            return new \Arca\PaymentGateways\ValueObjects\ChargeResult(
                status: \Arca\PaymentGateways\Enums\PaymentStatus::Paid,
            );
        }

        public function query($payment): \Arca\PaymentGateways\ValueObjects\ChargeResult
        {
            return new \Arca\PaymentGateways\ValueObjects\ChargeResult(
                status: \Arca\PaymentGateways\Enums\PaymentStatus::Pending,
            );
        }
    };

    $manager->extend('custom', fn () => $custom);

    expect($manager->driver('custom'))->toBe($custom)
        ->and($manager->driver('custom')->name())->toBe('custom');
});

it('creates a fake driver', function () {
    $manager = app(PaymentManager::class);

    $fake = $manager->fake();

    expect($fake)->toBeInstanceOf(FakeDriver::class)
        ->and($manager->driver())->toBe($fake);
});

it('creates a fake for a specific driver', function () {
    $manager = app(PaymentManager::class);

    $fake = $manager->fake('paypal');

    expect($manager->driver('paypal'))->toBe($fake);
});
