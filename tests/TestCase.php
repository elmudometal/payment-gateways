<?php

namespace Arca\PaymentGateways\Tests;

use Arca\PaymentGateways\PaymentGatewaysServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Arca\\PaymentGateways\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app): array
    {
        return [
            PaymentGatewaysServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('payment-gateways.default', 'webpay');
        $app['config']->set('payment-gateways.gateways.webpay', [
            'driver' => 'webpay',
            'environment' => 'sandbox',
            'commerce_code' => 'test-code',
            'api_key' => 'test-key',
        ]);
        $app['config']->set('payment-gateways.gateways.getnet', [
            'driver' => 'getnet',
            'environment' => 'sandbox',
            'login' => 'test-login',
            'tran_key' => 'test-key',
        ]);
        $app['config']->set('payment-gateways.gateways.paypal', [
            'driver' => 'paypal',
            'environment' => 'sandbox',
            'client_id' => 'test-client',
            'client_secret' => 'test-secret',
            'currency' => 'USD',
        ]);
        $app['config']->set('payment-gateways.gateways.flow', [
            'driver' => 'flow',
            'environment' => 'sandbox',
            'api_key' => 'test-api-key',
            'secret_key' => 'test-secret-key',
        ]);
        $app['config']->set('payment-gateways.gateways.niubiz', [
            'driver' => 'niubiz',
            'environment' => 'sandbox',
            'merchant_id' => 'test-merchant',
            'user' => 'test-user',
            'password' => 'test-pass',
            'currency' => 'PEN',
        ]);
        $app['config']->set('payment-gateways.gateways.mercadopago', [
            'driver' => 'mercadopago',
            'environment' => 'sandbox',
            'access_token' => 'TEST-fake-access-token',
        ]);
    }
}
