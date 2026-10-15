<?php

namespace Arca\PaymentGateways\Contracts;

use Arca\PaymentGateways\ValueObjects\WebhookResult;
use Illuminate\Http\Request;

interface SupportsWebhooks
{
    /**
     * Checks that the notification comes from the gateway (signature, shared secret).
     */
    public function verifyWebhook(Request $request): bool;

    /**
     * Resolves the local payment and its status from a verified notification.
     * Returns null for notifications that do not concern a known payment.
     */
    public function handleWebhook(Request $request): ?WebhookResult;
}
