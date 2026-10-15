<?php

use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\ValueObjects\ChargeResult;

it('describes the next step of the checkout', function () {
    $redirect = new ChargeResult(status: PaymentStatus::Pending, redirectUrl: 'https://gateway.test/pay');
    $view = new ChargeResult(status: PaymentStatus::Pending, view: 'payment-gateways::niubiz');

    expect($redirect->requiresRedirect())->toBeTrue()
        ->and($redirect->requiresView())->toBeFalse()
        ->and($view->requiresView())->toBeTrue()
        ->and($view->isPending())->toBeTrue();
});

it('tells approved and failed results apart', function () {
    expect((new ChargeResult(PaymentStatus::Paid))->isApproved())->toBeTrue()
        ->and((new ChargeResult(PaymentStatus::Failed))->isFailed())->toBeTrue()
        ->and((new ChargeResult(PaymentStatus::Authorized))->isApproved())->toBeFalse();
});
