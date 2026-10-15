<?php

namespace Arca\PaymentGateways\ValueObjects;

use Arca\PaymentGateways\Enums\PaymentStatus;

/**
 * What a gateway reported about a payment.
 *
 * `reference` and `amount` are what the gateway says it charged. When a driver sets them,
 * PaymentService checks them against the local payment before changing anything.
 */
final readonly class ChargeResult
{
    /**
     * @param  array<string, mixed>  $raw  The gateway response, stored as the payment voucher
     * @param  array<string, mixed>  $viewData
     */
    public function __construct(
        public PaymentStatus $status,
        public ?string $externalId = null,
        public ?string $transactionId = null,
        public ?string $authorizationCode = null,
        public ?string $reference = null,
        public ?Money $amount = null,
        public ?string $redirectUrl = null,
        public ?string $view = null,
        public array $viewData = [],
        public ?string $message = null,
        public array $raw = [],
    ) {}

    public function isApproved(): bool
    {
        return $this->status === PaymentStatus::Paid;
    }

    public function isPending(): bool
    {
        return $this->status === PaymentStatus::Pending;
    }

    public function isFailed(): bool
    {
        return $this->status === PaymentStatus::Failed;
    }

    public function requiresRedirect(): bool
    {
        return $this->redirectUrl !== null;
    }

    /**
     * The checkout is rendered on our side (an embedded form such as Niubiz).
     */
    public function requiresView(): bool
    {
        return $this->view !== null;
    }
}
