<?php

namespace Arca\PaymentGateways\ValueObjects;

final readonly class ChargeIntent
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public Money $money,
        public string $description,
        public ReturnUrls $urls,
        public ?Customer $customer = null,
        public array $metadata = [],
    ) {}
}
