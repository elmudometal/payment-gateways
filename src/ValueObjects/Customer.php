<?php

namespace Arca\PaymentGateways\ValueObjects;

final readonly class Customer
{
    public function __construct(
        public ?string $name = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $ip = null,
    ) {}

    public static function fromRequest(): self
    {
        return new self(ip: request()->ip());
    }
}
