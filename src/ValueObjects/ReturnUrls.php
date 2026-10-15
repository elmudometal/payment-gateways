<?php

namespace Arca\PaymentGateways\ValueObjects;

final readonly class ReturnUrls
{
    public function __construct(
        public string $returnUrl,
        public ?string $cancelUrl = null,
        public ?string $webhookUrl = null,
    ) {}
}
