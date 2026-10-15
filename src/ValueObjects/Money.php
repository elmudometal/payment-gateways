<?php

namespace Arca\PaymentGateways\ValueObjects;

use Arca\PaymentGateways\Enums\Currency;
use InvalidArgumentException;

/**
 * An amount in the currency's minor unit (cents for USD, pesos for CLP).
 */
final readonly class Money
{
    public function __construct(
        public int $amount,
        public Currency $currency,
    ) {
        if ($amount < 0) {
            throw new InvalidArgumentException('Money amount cannot be negative.');
        }
    }

    public static function of(int $amount, Currency|string $currency): self
    {
        return new self($amount, $currency instanceof Currency ? $currency : Currency::from($currency));
    }

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

    /**
     * Builds Money from a decimal amount as gateways report it (15000, 15000.0 or "150.50").
     */
    public static function fromDecimal(float|int|string $decimal, Currency|string $currency): self
    {
        $currency = $currency instanceof Currency ? $currency : Currency::from($currency);

        return new self((int) round((float) $decimal * (10 ** $currency->minorUnit())), $currency);
    }

    public function toDecimal(): float
    {
        return $this->amount / (10 ** $this->currency->minorUnit());
    }

    /**
     * The amount as a JSON number: an integer for currencies without decimals (15000, not 15000.0).
     */
    public function toNumber(): int|float
    {
        return $this->currency->minorUnit() === 0 ? $this->amount : $this->toDecimal();
    }

    /**
     * The decimal amount as a string with the currency's decimals ("15000", "150.50"), for API payloads.
     */
    public function toDecimalString(): string
    {
        return number_format($this->toDecimal(), $this->currency->minorUnit(), '.', '');
    }

    public function format(): string
    {
        return number_format($this->toDecimal(), $this->currency->minorUnit(), ',', '.').' '.$this->currency->value;
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount
            && $this->currency === $other->currency;
    }

    public function isLessThan(self $other): bool
    {
        return $this->currency === $other->currency && $this->amount < $other->amount;
    }
}
