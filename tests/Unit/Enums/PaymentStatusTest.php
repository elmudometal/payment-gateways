<?php

use Arca\PaymentGateways\Enums\PaymentStatus;

it('lets a pending payment move to any other status', function (PaymentStatus $to) {
    expect(PaymentStatus::Pending->canTransitionTo($to))->toBeTrue();
})->with(fn () => array_filter(PaymentStatus::cases(), fn ($s) => $s !== PaymentStatus::Pending));

it('never moves a payment back to pending', function (PaymentStatus $from) {
    expect($from->canTransitionTo(PaymentStatus::Pending))->toBeFalse();
})->with(PaymentStatus::cases());

it('only refunds or reverses a paid payment', function () {
    expect(PaymentStatus::Paid->canTransitionTo(PaymentStatus::Refunded))->toBeTrue()
        ->and(PaymentStatus::Paid->canTransitionTo(PaymentStatus::Reversed))->toBeTrue()
        ->and(PaymentStatus::Paid->canTransitionTo(PaymentStatus::Failed))->toBeFalse()
        ->and(PaymentStatus::Paid->canTransitionTo(PaymentStatus::Authorized))->toBeFalse();
});

it('lets a rejected payment be paid on a retry', function () {
    expect(PaymentStatus::Failed->canTransitionTo(PaymentStatus::Paid))->toBeTrue()
        ->and(PaymentStatus::Failed->canTransitionTo(PaymentStatus::Refunded))->toBeFalse();
});

it('keeps refunded and reversed payments final', function (PaymentStatus $final) {
    foreach (PaymentStatus::cases() as $to) {
        expect($final->canTransitionTo($to))->toBeFalse();
    }

    expect($final->isFinal())->toBeTrue();
})->with([PaymentStatus::Refunded, PaymentStatus::Reversed]);

it('does not treat the same status as a transition', function (PaymentStatus $status) {
    expect($status->canTransitionTo($status))->toBeFalse();
})->with(PaymentStatus::cases());

it('translates labels', function () {
    app()->setLocale('es');
    expect(PaymentStatus::Paid->label())->toBe('Pagado');

    app()->setLocale('en');
    expect(PaymentStatus::Failed->label())->toBe('Rejected');
});
