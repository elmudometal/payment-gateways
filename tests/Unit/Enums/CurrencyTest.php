<?php

use Arca\PaymentGateways\Enums\Currency;

it('CLP has zero minor units', function () {
    expect(Currency::CLP->minorUnit())->toBe(0);
});

it('USD has two minor units', function () {
    expect(Currency::USD->minorUnit())->toBe(2);
});

it('PEN has two minor units', function () {
    expect(Currency::PEN->minorUnit())->toBe(2);
});

it('all currencies have string values', function () {
    foreach (Currency::cases() as $currency) {
        expect($currency->value)->toBeString()->toHaveLength(3);
    }
});
