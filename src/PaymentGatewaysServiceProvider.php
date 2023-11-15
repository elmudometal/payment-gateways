<?php

namespace Arca\PaymentGateways;

use Arca\PaymentGateways\Commands\PaymentGatewaysCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class PaymentGatewaysServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('payment-gateways')
            ->hasConfigFile()
            ->hasViews()
            ->hasRoutes(['getnet', 'webpay', 'paypal'])
            ->hasMigration('create_payment-gateways_table')
            ->hasCommand(PaymentGatewaysCommand::class);
    }
}
