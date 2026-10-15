<?php

namespace Arca\PaymentGateways\ValueObjects;

use Arca\PaymentGateways\Enums\PaymentStatus;

final readonly class ChargeResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public PaymentStatus $status,
        public ?string $externalId = null,
        public ?string $authorizationCode = null,
        public ?string $redirectUrl = null,
        public ?string $message = null,
        public array $raw = [],
    ) {}

    public function isApproved(): bool
    {
        return $this->status === PaymentStatus::Paid;
    }

    public function requiresRedirect(): bool
    {
        return $this->redirectUrl !== null;
    }
}
