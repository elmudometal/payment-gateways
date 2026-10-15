<?php

namespace Arca\PaymentGateways\Tests;

use Arca\PaymentGateways\PaymentGatewaysServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            PaymentGatewaysServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        (include __DIR__.'/../database/migrations/create_payments_table.php.stub')->up();
        (include __DIR__.'/../database/migrations/create_payment_logs_table.php.stub')->up();
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('app.url', 'https://shop.example.com');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('payment-gateways.default', 'webpay');
        $app['config']->set('payment-gateways.http.retries', 0);

        $gateways = [
            'webpay' => ['environment' => 'sandbox'],
            'getnet' => ['environment' => 'sandbox', 'login' => 'test-login', 'tran_key' => 'test-tran-key'],
            'paypal' => ['environment' => 'sandbox', 'client_id' => 'test-client', 'client_secret' => 'test-secret', 'webhook_id' => 'WH-TEST'],
            'flow' => ['environment' => 'sandbox', 'api_key' => 'test-api-key', 'secret_key' => 'test-secret-key'],
            'niubiz' => ['environment' => 'sandbox', 'merchant_id' => '456879852', 'user' => 'test-user', 'password' => 'test-pass'],
            'mercadopago' => ['environment' => 'sandbox', 'access_token' => 'TEST-token', 'webhook_secret' => 'mp-secret'],
        ];

        foreach ($gateways as $name => $config) {
            $app['config']->set("payment-gateways.gateways.{$name}", ['driver' => $name] + $config);
        }
    }
}
