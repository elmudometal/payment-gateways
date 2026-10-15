<?php

namespace Arca\PaymentGateways\Http\Controllers;

use Arca\PaymentGateways\Exceptions\GatewayCommunicationException;
use Arca\PaymentGateways\Exceptions\GatewayConfigurationException;
use Arca\PaymentGateways\Exceptions\InvalidWebhookSignatureException;
use Arca\PaymentGateways\Exceptions\PaymentVerificationException;
use Arca\PaymentGateways\Exceptions\UnsupportedFeatureException;
use Arca\PaymentGateways\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(
        protected readonly PaymentService $payments,
    ) {}

    /**
     * Answers 2xx once the notification is handled or can never be (so the gateway stops retrying),
     * and 5xx when the gateway API could not be reached (so it retries later).
     */
    public function handle(string $gateway, Request $request): JsonResponse
    {
        if (! is_array(config("payment-gateways.gateways.{$gateway}"))) {
            return response()->json(['message' => 'not found'], 404);
        }

        try {
            $payment = $this->payments->handleWebhook($gateway, $request);
        } catch (UnsupportedFeatureException) {
            return response()->json(['message' => 'not found'], 404);
        } catch (GatewayConfigurationException $e) {
            // Missing credentials: answer 5xx so the gateway retries once the config is fixed.
            $this->log('error', $gateway, $e->getMessage());

            return response()->json(['message' => 'error'], 500);
        } catch (InvalidWebhookSignatureException $e) {
            $this->log('warning', $gateway, $e->getMessage());

            return response()->json(['message' => 'invalid signature'], 401);
        } catch (PaymentVerificationException $e) {
            $this->log('error', $gateway, $e->getMessage(), $e->context);

            return response()->json(['message' => 'ignored']);
        } catch (GatewayCommunicationException $e) {
            $this->log('error', $gateway, $e->getMessage(), $e->context);

            return response()->json(['message' => 'error'], 500);
        }

        return response()->json(['message' => $payment ? 'ok' : 'ignored']);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function log(string $level, string $gateway, string $message, array $context = []): void
    {
        Log::channel(config('payment-gateways.log_channel'))->{$level}("[payment-gateways:{$gateway}] Webhook: {$message}", $context);
    }
}
