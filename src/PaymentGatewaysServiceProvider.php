<?php

namespace Arca\PaymentGateways;

use Arca\PaymentGateways\Console\Commands\MakePaymentDriverCommand;
use Arca\PaymentGateways\Services\PaymentService;
use Arca\PaymentGateways\Support\PaymentManager;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class PaymentGatewaysServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('payment-gateways')
            ->hasConfigFile()
            ->hasViews()
            ->hasRoutes(['payments'])
            ->hasMigrations(['create_payments_table'])
            ->runsMigrations(true)
            ->hasCommand(MakePaymentDriverCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(PaymentManager::class, function ($app) {
            return new PaymentManager($app);
        });

        $this->app->singleton(PaymentService::class, function ($app) {
            return new PaymentService($app->make(PaymentManager::class));
        });
    }
}
