<?php

namespace Arca\PaymentGateways\Contracts;

use Illuminate\Http\Request;

interface SupportsWebhooks
{
    public function verifyWebhook(Request $request): bool;

    public function handleWebhook(Request $request): void;
}
