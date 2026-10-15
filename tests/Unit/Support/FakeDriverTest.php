<?php

use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\Facades\PaymentGateways;
use Arca\PaymentGateways\ValueObjects\ChargeResult;
use Arca\PaymentGateways\ValueObjects\Money;

it('fakes a whole checkout through the facade', function () {
    $fake = PaymentGateways::fake('webpay');

    $payment = PaymentGateways::create('webpay', Money::CLP(5000), 'Order 1');
    $result = PaymentGateways::charge($payment);

    expect($result->redirectUrl)->toBe('https://checkout.fake/'.$payment->uuid);

    $this->get(route('payments.commit', $payment))->assertRedirect(route('payments.success', $payment));

    $fake->assertCharged()->assertCompleted();
    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid);
});

it('answers what the test asks for', function () {
    $fake = PaymentGateways::fake('webpay')
        ->completeWith(new ChargeResult(status: PaymentStatus::Failed, message: 'Card declined'));

    $payment = PaymentGateways::create('webpay', Money::CLP(5000));
    PaymentGateways::charge($payment);

    $this->get(route('payments.commit', $payment))->assertRedirect(route('payments.failed', $payment));

    expect($payment->fresh()->failureMessage())->toBe('Card declined');
    $fake->assertCharged();
});

it('records refunds', function () {
    $fake = PaymentGateways::fake('webpay');

    $payment = PaymentGateways::create('webpay', Money::CLP(5000));
    PaymentGateways::charge($payment);
    PaymentGateways::complete($payment, request());

    PaymentGateways::refund($payment);

    $fake->assertRefunded();
    expect($payment->status)->toBe(PaymentStatus::Refunded);
});
