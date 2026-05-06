<?php

namespace Arca\PaymentGateways\Support;

use Arca\PaymentGateways\Contracts\PaymentGateway;
use Arca\PaymentGateways\Drivers\Flow\FlowDriver;
use Arca\PaymentGateways\Drivers\Getnet\GetnetDriver;
use Arca\PaymentGateways\Drivers\Niubiz\NiubizDriver;
use Arca\PaymentGateways\Drivers\Paypal\PaypalDriver;
use Arca\PaymentGateways\Drivers\Webpay\WebpayDriver;
use Arca\PaymentGateways\Exceptions\GatewayConfigurationException;
use Arca\PaymentGateways\Support\Testing\FakeDriver;
use Closure;
use Illuminate\Support\Manager;

/**
 * @method PaymentGateway driver(?string $driver = null)
 */
class PaymentManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return $this->config->get('payment-gateways.default', 'webpay');
    }

    public function createWebpayDriver(): WebpayDriver
    {
        return new WebpayDriver(
            $this->gatewayConfig('webpay'),
        );
    }

    public function createGetnetDriver(): GetnetDriver
    {
        return new GetnetDriver(
            $this->gatewayConfig('getnet'),
        );
    }

    public function createPaypalDriver(): PaypalDriver
    {
        return new PaypalDriver(
            $this->gatewayConfig('paypal'),
        );
    }

    public function createFlowDriver(): FlowDriver
    {
        return new FlowDriver(
            $this->gatewayConfig('flow'),
        );
    }

    public function createNiubizDriver(): NiubizDriver
    {
        return new NiubizDriver(
            $this->gatewayConfig('niubiz'),
        );
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

    public function fake(?string $driver = null): FakeDriver
    {
        $fake = new FakeDriver;

        if ($driver) {
            $this->extend($driver, fn () => $fake);
        } else {
            $this->extend($this->getDefaultDriver(), fn () => $fake);
        }

        return $fake;
    }

    /**
     * @param  Closure(mixed, array<string, mixed>): PaymentGateway  $callback
     */
    public function extend($driver, Closure $callback): static
    {
        return parent::extend($driver, $callback);
    }
}
