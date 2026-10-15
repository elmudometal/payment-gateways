<?php

namespace Arca\PaymentGateways\Drivers;

use Arca\PaymentGateways\Contracts\PaymentGateway;
use Arca\PaymentGateways\Enums\Environment;
use Arca\PaymentGateways\Exceptions\GatewayCommunicationException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Log;

abstract class AbstractDriver implements PaymentGateway
{
    protected Environment $environment;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected readonly array $config,
    ) {
        $this->environment = isset($config['environment'])
            ? Environment::from($config['environment'])
            : Environment::fromApp();
    }

    protected function http(): PendingRequest
    {
        return app(HttpFactory::class)
            ->baseUrl($this->baseUrl())
            ->throw(fn ($response, $e) => throw new GatewayCommunicationException(
                message: "HTTP error from [{$this->name()}]: {$e->getMessage()}",
                gateway: $this->name(),
                context: ['status' => $response->status(), 'body' => $response->body()],
                previous: $e,
            ));
    }

    abstract protected function baseUrl(): string;

    protected function isSandbox(): bool
    {
        return $this->environment === Environment::Sandbox;
    }

    protected function log(string $message, array $context = []): void
    {
        Log::channel(
            $this->config['log_channel'] ?? config('logging.default')
        )->info("[payment-gateways:{$this->name()}] {$message}", $context);
    }

    protected function logError(string $message, array $context = []): void
    {
        Log::channel(
            $this->config['log_channel'] ?? config('logging.default')
        )->error("[payment-gateways:{$this->name()}] {$message}", $context);
    }
}
