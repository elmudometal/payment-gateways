<?php

use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\ValueObjects\ChargeResult;

it('detects approved status', function () {
    $result = new ChargeResult(status: PaymentStatus::Paid);

    expect($result->isApproved())->toBeTrue();
});

it('detects non-approved status', function () {
    $result = new ChargeResult(status: PaymentStatus::Failed);

    expect($result->isApproved())->toBeFalse();
});

it('detects redirect requirement', function () {
    $result = new ChargeResult(
        status: PaymentStatus::Pending,
        redirectUrl: 'https://gateway.example.com/pay',
    );

    expect($result->requiresRedirect())->toBeTrue();
});

it('detects no redirect needed', function () {
    $result = new ChargeResult(status: PaymentStatus::Paid);

    expect($result->requiresRedirect())->toBeFalse();
});

it('stores raw response data', function () {
    $raw = ['transaction_id' => '123', 'status' => 'ok'];
    $result = new ChargeResult(
        status: PaymentStatus::Paid,
        raw: $raw,
    );

    expect($result->raw)->toBe($raw);
});
