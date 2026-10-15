<?php

use Arca\PaymentGateways\Enums\Currency;

it('has no decimals for CLP and PYG', function (Currency $currency) {
    expect($currency->minorUnit())->toBe(0);
})->with([Currency::CLP, Currency::PYG]);

it('has two decimals for the other currencies', function (Currency $currency) {
    expect($currency->minorUnit())->toBe(2);
})->with([Currency::USD, Currency::PEN, Currency::ARS, Currency::BRL, Currency::MXN, Currency::COP, Currency::UYU, Currency::EUR]);
