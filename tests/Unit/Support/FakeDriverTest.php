<?php

use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\Support\Testing\FakeDriver;
use Arca\PaymentGateways\ValueObjects\ChargeIntent;
use Arca\PaymentGateways\ValueObjects\ChargeResult;
use Arca\PaymentGateways\ValueObjects\Money;
use Arca\PaymentGateways\ValueObjects\RefundResult;
use Arca\PaymentGateways\ValueObjects\ReturnUrls;

it('records charges and allows assertions', function () {
    $fake = new FakeDriver;
    $payment = Payment::factory()->create();
    $intent = new ChargeIntent(
        money: Money::CLP(1000),
        description: 'Test',
        urls: new ReturnUrls(returnUrl: 'https://example.com'),
    );

    $result = $fake->charge($payment, $intent);

    expect($result->isApproved())->toBeTrue();
    $fake->assertCharged(1);
});

it('allows custom charge response', function () {
    $fake = new FakeDriver;
    $fake->respondWith(new ChargeResult(
        status: PaymentStatus::Failed,
        message: 'Declined',
    ));

    $payment = Payment::factory()->create();
    $intent = new ChargeIntent(
        money: Money::CLP(1000),
        description: 'Test',
        urls: new ReturnUrls(returnUrl: 'https://example.com'),
    );

    $result = $fake->charge($payment, $intent);

    expect($result->isApproved())->toBeFalse()
        ->and($result->message)->toBe('Declined');
});

it('records refunds', function () {
    $fake = new FakeDriver;
    $payment = Payment::factory()->paid()->create();

    $result = $fake->refund($payment);

    expect($result->isRefunded())->toBeTrue();
    $fake->assertRefunded(1);
});

it('asserts not charged', function () {
    $fake = new FakeDriver;

    $fake->assertNotCharged();
});

it('has a name', function () {
    expect((new FakeDriver)->name())->toBe('fake');
});
