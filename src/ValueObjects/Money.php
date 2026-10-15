<?php

namespace Arca\PaymentGateways\ValueObjects;

use Arca\PaymentGateways\Enums\Currency;

final readonly class Money
{
    public function __construct(
        public int $amount,
        public Currency $currency,
    ) {}

    public static function CLP(int $amount): self
    {
        return new self($amount, Currency::CLP);
    }

    public static function USD(int $cents): self
    {
        return new self($cents, Currency::USD);
    }

    public static function PEN(int $cents): self
    {
        return new self($cents, Currency::PEN);
    }

    public static function fromDecimal(float $decimal, Currency $currency): self
    {
        $amount = (int) round($decimal * (10 ** $currency->minorUnit()));

        return new self($amount, $currency);
    }

    public function toDecimal(): float
    {
        return $this->amount / (10 ** $this->currency->minorUnit());
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount
            && $this->currency === $other->currency;
    }
}
