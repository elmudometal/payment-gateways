<?php

namespace Arca\PaymentGateways\Http\Controllers;

use Arca\PaymentGateways\Contracts\SupportsWebhooks;
use Arca\PaymentGateways\Events\WebhookReceived;
use Arca\PaymentGateways\Exceptions\InvalidWebhookSignatureException;
use Arca\PaymentGateways\Support\PaymentManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class WebhookController extends Controller
{
    public function __construct(
        protected readonly PaymentManager $manager,
    ) {}

    public function handle(string $gateway, Request $request): JsonResponse
    {
        $driver = $this->manager->driver($gateway);

        if (! $driver instanceof SupportsWebhooks) {
            return response()->json(['error' => 'Webhooks not supported'], 404);
        }

        if (! $driver->verifyWebhook($request)) {
            throw new InvalidWebhookSignatureException(
                message: 'Invalid webhook signature',
                gateway: $gateway,
            );
        }

        WebhookReceived::dispatch($gateway, $request->all());

        $driver->handleWebhook($request);

        return response()->json(['message' => 'ok']);
    }
}
