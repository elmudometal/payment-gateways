<?php

namespace Arca\PaymentGateways\Drivers;

use Arca\PaymentGateways\Contracts\PaymentGateway;
use Arca\PaymentGateways\Enums\Environment;
use Arca\PaymentGateways\Exceptions\GatewayCommunicationException;
use Arca\PaymentGateways\Exceptions\GatewayConfigurationException;
use Arca\PaymentGateways\Models\Payment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

abstract class AbstractDriver implements PaymentGateway
{
    protected Environment $environment;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected readonly array $config,
    ) {
        $this->environment = ! empty($config['environment'])
            ? Environment::from($config['environment'])
            : Environment::fromApp();
    }

    abstract protected function baseUrl(): string;

    public function reference(Payment $payment): string
    {
        return $payment->uuid;
    }

    /**
     * An HTTP client for the gateway API with timeouts. Failed responses throw
     * GatewayCommunicationException.
     *
     * Pass $retry for reads: they are retried on connection errors and 5xx answers. Writes are not,
     * so a slow answer never creates the same charge twice.
     */
    protected function http(bool $retry = false): PendingRequest
    {
        $http = config('payment-gateways.http', []);

        $request = app(HttpFactory::class)
            ->baseUrl($this->baseUrl())
            ->acceptJson()
            ->connectTimeout((int) ($http['connect_timeout'] ?? 5))
            ->timeout((int) ($http['timeout'] ?? 15));

        if ($retry && (int) ($http['retries'] ?? 2) > 0) {
            $request->retry(
                (int) ($http['retries'] ?? 2) + 1,
                200,
                fn (Throwable $e): bool => $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->serverError()),
                throw: false,
            );
        }

        return $request->throw(fn (Response $response, RequestException $e) => throw new GatewayCommunicationException(
            message: "HTTP {$response->status()} from [{$this->name()}]",
            gateway: $this->name(),
            context: ['status' => $response->status(), 'body' => $response->json() ?? $response->body()],
            previous: $e,
        ));
    }

    protected function isSandbox(): bool
    {
        return $this->environment === Environment::Sandbox;
    }

    /**
     * @param  list<string>  $keys
     */
    protected function requireConfig(array $keys): void
    {
        foreach ($keys as $key) {
            if (empty($this->config[$key])) {
                throw new GatewayConfigurationException(
                    message: "Missing [{$key}] in the [{$this->name()}] gateway config.",
                    gateway: $this->name(),
                );
            }
        }
    }

    /**
     * Wraps an unexpected error from an SDK or the HTTP client.
     */
    protected function communicationError(Throwable $e, string $action): GatewayCommunicationException
    {
        if ($e instanceof GatewayCommunicationException) {
            $this->logError("{$action} failed", ['error' => $e->getMessage(), 'context' => $e->context]);

            return $e;
        }

        $this->logError("{$action} failed", ['error' => $e->getMessage()]);

        return new GatewayCommunicationException(
            message: "{$action} failed: {$e->getMessage()}",
            gateway: $this->name(),
            previous: $e,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function log(string $message, array $context = []): void
    {
        Log::channel($this->logChannel())->info("[payment-gateways:{$this->name()}] {$message}", $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function logWarning(string $message, array $context = []): void
    {
        Log::channel($this->logChannel())->warning("[payment-gateways:{$this->name()}] {$message}", $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function logError(string $message, array $context = []): void
    {
        Log::channel($this->logChannel())->error("[payment-gateways:{$this->name()}] {$message}", $context);
    }

    protected function logChannel(): ?string
    {
        return $this->config['log_channel'] ?? config('payment-gateways.log_channel');
    }
}
