<?php

namespace Arca\PaymentGateways\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched for every notification whose signature was verified, before it is processed.
 */
class WebhookReceived
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $gateway,
        public readonly array $payload,
    ) {}
}
