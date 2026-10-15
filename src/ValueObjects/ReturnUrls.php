<?php

namespace Arca\PaymentGateways\ValueObjects;

use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\Support\Url;

final readonly class ReturnUrls
{
    public function __construct(
        public string $returnUrl,
        public ?string $cancelUrl = null,
        public ?string $webhookUrl = null,
    ) {}

    /**
     * The package's own commit and webhook routes for a payment.
     */
    public static function forPayment(Payment $payment): self
    {
        $returnUrl = route('payments.commit', $payment);

        return new self(
            returnUrl: $returnUrl,
            cancelUrl: $returnUrl.'?cancelled=1',
            webhookUrl: route('payments.webhook', $payment->gateway),
        );
    }

    /**
     * Whether the gateway can call the webhook URL (HTTPS on a public host).
     */
    public function webhookIsReachable(): bool
    {
        return $this->webhookUrl !== null && Url::isPublic($this->webhookUrl);
    }

    public function returnIsPublic(): bool
    {
        return Url::isPublic($this->returnUrl);
    }
}
