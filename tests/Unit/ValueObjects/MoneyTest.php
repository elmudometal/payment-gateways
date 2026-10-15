<?php

use Arca\PaymentGateways\Enums\Currency;
use Arca\PaymentGateways\ValueObjects\Money;

it('keeps amounts in the minor unit', function () {
    expect(Money::CLP(15000)->toDecimal())->toBe(15000.0)
        ->and(Money::USD(1999)->toDecimal())->toBe(19.99)
        ->and(Money::PEN(1050)->toDecimalString())->toBe('10.50')
        ->and(Money::CLP(15000)->toDecimalString())->toBe('15000');
});

it('builds money from gateway decimals', function (mixed $decimal, Currency|string $currency, int $amount) {
    expect(Money::fromDecimal($decimal, $currency)->amount)->toBe($amount);
})->with([
    [15000, Currency::CLP, 15000],
    [15000.0, Currency::CLP, 15000],
    ['150.50', Currency::USD, 15050],
    [0.1 + 0.2, Currency::USD, 30],
    ['19.99', 'PEN', 1999],
]);

it('compares amounts in the same currency', function () {
    expect(Money::CLP(100)->equals(Money::CLP(100)))->toBeTrue()
        ->and(Money::CLP(100)->equals(Money::of(100, 'USD')))->toBeFalse()
        ->and(Money::CLP(99)->isLessThan(Money::CLP(100)))->toBeTrue()
        ->and(Money::CLP(100)->isLessThan(Money::CLP(100)))->toBeFalse()
        ->and(Money::of(1, 'USD')->isLessThan(Money::CLP(100)))->toBeFalse();
});

it('formats for people', function () {
    expect(Money::CLP(1500000)->format())->toBe('1.500.000 CLP')
        ->and(Money::USD(123456)->format())->toBe('1.234,56 USD');
});

it('refuses negative amounts', function () {
    Money::CLP(-1);
})->throws(InvalidArgumentException::class);

it('is an integer JSON number for currencies without decimals', function () {
    expect(Money::CLP(15000)->toNumber())->toBe(15000)
        ->and(Money::USD(1999)->toNumber())->toBe(19.99);
});
