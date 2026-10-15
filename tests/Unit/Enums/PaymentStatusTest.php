<?php

use Arca\PaymentGateways\Enums\PaymentStatus;

it('has correct labels', function () {
    expect(PaymentStatus::Pending->label())->toBe('Pendiente')
        ->and(PaymentStatus::Paid->label())->toBe('Pagado')
        ->and(PaymentStatus::Failed->label())->toBe('Rechazado')
        ->and(PaymentStatus::Refunded->label())->toBe('Reembolsado');
});

it('identifies final statuses', function () {
    expect(PaymentStatus::Paid->isFinal())->toBeTrue()
        ->and(PaymentStatus::Failed->isFinal())->toBeTrue()
        ->and(PaymentStatus::Refunded->isFinal())->toBeTrue()
        ->and(PaymentStatus::Reversed->isFinal())->toBeTrue()
        ->and(PaymentStatus::Pending->isFinal())->toBeFalse()
        ->and(PaymentStatus::Authorized->isFinal())->toBeFalse();
});
