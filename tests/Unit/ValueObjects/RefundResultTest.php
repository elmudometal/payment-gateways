<?php

use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\ValueObjects\RefundResult;

it('is refunded only with a refunded status', function () {
    expect((new RefundResult(PaymentStatus::Refunded))->isRefunded())->toBeTrue()
        ->and((new RefundResult(PaymentStatus::Paid))->isRefunded())->toBeFalse();
});
