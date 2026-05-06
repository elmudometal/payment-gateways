<?php

use Arca\PaymentGateways\Enums\Currency;
use Arca\PaymentGateways\ValueObjects\Money;

it('creates CLP money with zero minor units', function () {
    $money = Money::CLP(50000);

    expect($money->amount)->toBe(50000)
        ->and($money->currency)->toBe(Currency::CLP)
        ->and($money->toDecimal())->toBe(50000.0);
});

it('creates USD money in cents', function () {
    $money = Money::USD(1999);

    expect($money->amount)->toBe(1999)
        ->and($money->currency)->toBe(Currency::USD)
        ->and($money->toDecimal())->toBe(19.99);
});

it('creates PEN money in cents', function () {
    $money = Money::PEN(3500);

    expect($money->amount)->toBe(3500)
        ->and($money->toDecimal())->toBe(35.0);
});

it('creates from decimal correctly', function () {
    $money = Money::fromDecimal(19.99, Currency::USD);

    expect($money->amount)->toBe(1999)
        ->and($money->toDecimal())->toBe(19.99);
});

it('creates from decimal for CLP (zero minor units)', function () {
    $money = Money::fromDecimal(50000, Currency::CLP);

    expect($money->amount)->toBe(50000);
});

it('compares equality correctly', function () {
    $a = Money::CLP(1000);
    $b = Money::CLP(1000);
    $c = Money::CLP(2000);
    $d = Money::USD(1000);

    expect($a->equals($b))->toBeTrue()
        ->and($a->equals($c))->toBeFalse()
        ->and($a->equals($d))->toBeFalse();
});

it('rounds correctly when creating from decimal', function () {
    $money = Money::fromDecimal(19.995, Currency::USD);

    expect($money->amount)->toBe(2000);
});
