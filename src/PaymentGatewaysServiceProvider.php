<?php

namespace Arca\PaymentGateways;

use Arca\PaymentGateways\Console\Commands\MakePaymentDriverCommand;
use Arca\PaymentGateways\Console\Commands\SyncPaymentsCommand;
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
            ->hasTranslations()
            ->hasRoutes(['payments'])
            ->hasMigrations(['create_payments_table', 'create_payment_logs_table'])
            ->hasCommands([
                MakePaymentDriverCommand::class,
                SyncPaymentsCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(PaymentManager::class, fn ($app) => new PaymentManager($app));

        $this->app->singleton(PaymentService::class, fn ($app) => new PaymentService($app->make(PaymentManager::class)));
    }
}
