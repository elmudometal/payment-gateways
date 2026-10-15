<?php

namespace Arca\PaymentGateways\Support;

use Arca\PaymentGateways\Contracts\PaymentGateway;
use Arca\PaymentGateways\Drivers\Flow\FlowDriver;
use Arca\PaymentGateways\Drivers\Getnet\GetnetDriver;
use Arca\PaymentGateways\Drivers\MercadoPago\MercadoPagoDriver;
use Arca\PaymentGateways\Drivers\Niubiz\NiubizDriver;
use Arca\PaymentGateways\Drivers\Paypal\PaypalDriver;
use Arca\PaymentGateways\Drivers\Webpay\WebpayDriver;
use Arca\PaymentGateways\Exceptions\GatewayConfigurationException;
use Arca\PaymentGateways\Support\Testing\FakeDriver;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Manager;

/**
 * Resolves gateway drivers by name. Each name maps to `payment-gateways.gateways.{name}`; its
 * `driver` key picks the implementation, so one driver can serve several configured accounts.
 *
 * @method PaymentGateway driver(?string $driver = null)
 */
class PaymentManager extends Manager
{
    /**
     * @var array<string, class-string<PaymentGateway>>
     */
    protected array $builtIn = [
        'webpay' => WebpayDriver::class,
        'getnet' => GetnetDriver::class,
        'paypal' => PaypalDriver::class,
        'flow' => FlowDriver::class,
        'niubiz' => NiubizDriver::class,
        'mercadopago' => MercadoPagoDriver::class,
    ];

    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('payment-gateways.default', 'webpay');
    }

    /**
     * @param  string  $driver
     */
    protected function createDriver($driver): PaymentGateway
    {
        if (isset($this->customCreators[$driver])) {
            return $this->callCustomCreator($driver);
        }

        // Drivers find their payments by this name: with two accounts of one driver
        // ("webpay_courses", "webpay_workshops") it is the account, not the driver.
        $config = ['gateway' => $driver] + $this->gatewayConfig($driver);
        $implementation = $config['driver'] ?? $driver;

        if (isset($this->customCreators[$implementation])) {
            return $this->customCreators[$implementation]($this->container, $config);
        }

        if (! isset($this->builtIn[$implementation])) {
            throw new GatewayConfigurationException(
                message: "Driver [{$implementation}] for gateway [{$driver}] is not supported.",
                gateway: $driver,
            );
        }

        $class = $this->builtIn[$implementation];

        return new $class($config);
    }

    /**
     * Custom creators receive the gateway config as second argument.
     *
     * @param  string  $driver
     */
    protected function callCustomCreator($driver): PaymentGateway
    {
        $config = $this->config->get("payment-gateways.gateways.{$driver}");

        return $this->customCreators[$driver]($this->container, is_array($config) ? $config : []);
    }

    /**
     * @return array<string, mixed>
     */
    protected function gatewayConfig(string $name): array
    {
        $config = $this->config->get("payment-gateways.gateways.{$name}");

        if (! is_array($config)) {
            throw new GatewayConfigurationException(
                message: "Gateway [{$name}] is not configured.",
                gateway: $name,
            );
        }

        return $config;
    }

    /**
     * Replaces a gateway (the default one when null) with a fake for tests.
     */
    public function fake(?string $driver = null): FakeDriver
    {
        $driver ??= $this->getDefaultDriver();
        $fake = new FakeDriver($driver);

        $this->extend($driver, fn () => $fake);

        return $fake;
    }

    /**
     * @param  string  $driver
     * @param  Closure(Container, array<string, mixed>): PaymentGateway  $callback
     */
    public function extend($driver, Closure $callback): static
    {
        unset($this->drivers[$driver]);

        return parent::extend($driver, $callback);
    }
}
