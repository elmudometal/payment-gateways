<?php

use Arca\PaymentGateways\Contracts\PaymentGateway;
use Arca\PaymentGateways\Enums\Currency;
use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\Events\PaymentApproved;
use Arca\PaymentGateways\Events\PaymentInitiated;
use Arca\PaymentGateways\Events\PaymentRefunded;
use Arca\PaymentGateways\Events\PaymentRejected;
use Arca\PaymentGateways\Exceptions\UnsupportedFeatureException;
use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\Services\PaymentService;
use Arca\PaymentGateways\Support\PaymentManager;
use Arca\PaymentGateways\ValueObjects\ChargeIntent;
use Arca\PaymentGateways\ValueObjects\ChargeResult;
use Arca\PaymentGateways\ValueObjects\Money;
use Arca\PaymentGateways\ValueObjects\ReturnUrls;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->service = app(PaymentService::class);
    $this->manager = app(PaymentManager::class);
});

it('creates a payment record', function () {
    $intent = new ChargeIntent(
        money: Money::CLP(50000),
        description: 'Test payment',
        urls: new ReturnUrls(returnUrl: 'https://example.com/return'),
    );

    $payment = $this->service->create('webpay', $intent);

    expect($payment)->toBeInstanceOf(Payment::class)
        ->and($payment->exists)->toBeTrue()
        ->and($payment->gateway)->toBe('webpay')
        ->and($payment->amount)->toBe(50000)
        ->and($payment->currency)->toBe(Currency::CLP)
        ->and($payment->description)->toBe('Test payment')
        ->and($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->uuid)->not->toBeEmpty();
});

it('charges a payment and dispatches events', function () {
    Event::fake();

    $fake = $this->manager->fake('webpay');

    $intent = new ChargeIntent(
        money: Money::CLP(50000),
        description: 'Test',
        urls: new ReturnUrls(returnUrl: 'https://example.com/return'),
    );

    $payment = $this->service->create('webpay', $intent);
    $result = $this->service->charge($payment, $intent);

    expect($result->isApproved())->toBeTrue()
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($payment->fresh()->paid_at)->not->toBeNull();

    $fake->assertCharged(1);

    Event::assertDispatched(PaymentInitiated::class);
    Event::assertDispatched(PaymentApproved::class);
});

it('handles failed charge', function () {
    Event::fake();

    $fake = $this->manager->fake('webpay');
    $fake->respondWith(new ChargeResult(
        status: PaymentStatus::Failed,
        message: 'Insufficient funds',
    ));

    $intent = new ChargeIntent(
        money: Money::CLP(50000),
        description: 'Test',
        urls: new ReturnUrls(returnUrl: 'https://example.com/return'),
    );

    $payment = $this->service->create('webpay', $intent);
    $result = $this->service->charge($payment, $intent);

    expect($result->isApproved())->toBeFalse()
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Failed);

    Event::assertDispatched(PaymentRejected::class);
    Event::assertNotDispatched(PaymentApproved::class);
});

it('queries payment status', function () {
    $fake = $this->manager->fake('webpay');

    $payment = Payment::factory()->gateway('webpay')->create();
    $result = $this->service->query($payment);

    expect($result->isApproved())->toBeTrue();
    $fake->assertQueried(1);
});

it('refunds a payment', function () {
    Event::fake();

    $fake = $this->manager->fake('webpay');

    $payment = Payment::factory()->gateway('webpay')->paid()->create();
    $result = $this->service->refund($payment);

    expect($result->isRefunded())->toBeTrue()
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Refunded);

    $fake->assertRefunded(1);
    Event::assertDispatched(PaymentRefunded::class);
});

it('throws on unsupported refund', function () {
    $this->manager->extend('no-refund', fn () => new class implements PaymentGateway
    {
        public function name(): string
        {
            return 'no-refund';
        }

        public function charge($p, $i): ChargeResult
        {
            return new ChargeResult(status: PaymentStatus::Paid);
        }

        public function query($p): ChargeResult
        {
            return new ChargeResult(status: PaymentStatus::Pending);
        }
    });

    $payment = Payment::factory()->create(['gateway' => 'no-refund']);

    $this->service->refund($payment);
})->throws(UnsupportedFeatureException::class);
