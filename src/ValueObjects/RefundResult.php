<?php

namespace Arca\PaymentGateways\ValueObjects;

use Arca\PaymentGateways\Enums\PaymentStatus;

final readonly class RefundResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public PaymentStatus $status,
        public ?string $refundId = null,
        public ?Money $refundedAmount = null,
        public ?string $message = null,
        public array $raw = [],
    ) {}

    public function isRefunded(): bool
    {
        return $this->status === PaymentStatus::Refunded;
    }
}
