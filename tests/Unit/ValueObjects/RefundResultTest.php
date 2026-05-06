<?php

use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\ValueObjects\Money;
use Arca\PaymentGateways\ValueObjects\RefundResult;

it('detects refunded status', function () {
    $result = new RefundResult(
        status: PaymentStatus::Refunded,
        refundId: 'ref-123',
    );

    expect($result->isRefunded())->toBeTrue()
        ->and($result->refundId)->toBe('ref-123');
});

it('detects non-refunded status', function () {
    $result = new RefundResult(
        status: PaymentStatus::Paid,
        message: 'Refund failed',
    );

    expect($result->isRefunded())->toBeFalse();
});

it('can hold partial refund amount', function () {
    $amount = Money::USD(500);
    $result = new RefundResult(
        status: PaymentStatus::Refunded,
        refundedAmount: $amount,
    );

    expect($result->refundedAmount)->toBe($amount);
});
