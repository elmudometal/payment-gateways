<?php

namespace Arca\PaymentGateways\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class MakePaymentDriverCommand extends Command
{
    protected $signature = 'make:payment-driver {name : The name of the payment driver (e.g. MercadoPago)}';

    protected $description = 'Scaffold a new payment gateway driver';

    public function handle(Filesystem $files): int
    {
        $name = $this->argument('name');
        $studly = Str::studly($name);
        $lower = Str::lower($name);

        $dir = app_path("PaymentDrivers/{$studly}");

        if ($files->isDirectory($dir)) {
            $this->error("Driver directory [{$dir}] already exists.");

            return self::FAILURE;
        }

        $files->makeDirectory($dir, 0755, true);

        $files->put("{$dir}/{$studly}Driver.php", $this->driverStub($studly, $lower));

        $this->components->info("Payment driver [{$studly}] created at [{$dir}].");
        $this->newLine();
        $this->line("  Register it in a service provider:");
        $this->newLine();
        $this->line("  <comment>PaymentGateways::extend('{$lower}', function (\$app, \$config) {</comment>");
        $this->line("  <comment>    return new \\App\\PaymentDrivers\\{$studly}\\{$studly}Driver(\$config);</comment>");
        $this->line("  <comment>});</comment>");
        $this->newLine();
        $this->line("  Then add the gateway config in <comment>config/payment-gateways.php</comment>:");
        $this->newLine();
        $this->line("  <comment>'{$lower}' => [</comment>");
        $this->line("  <comment>    'driver' => '{$lower}',</comment>");
        $this->line("  <comment>    'environment' => env('".Str::upper($name)."_ENV', 'sandbox'),</comment>");
        $this->line("  <comment>    // your credentials...</comment>");
        $this->line("  <comment>],</comment>");

        return self::SUCCESS;
    }

    protected function driverStub(string $studly, string $lower): string
    {
        return <<<PHP
        <?php

        namespace App\PaymentDrivers\\{$studly};

        use Arca\PaymentGateways\Contracts\PaymentGateway;
        use Arca\PaymentGateways\Drivers\AbstractDriver;
        use Arca\PaymentGateways\Enums\PaymentStatus;
        use Arca\PaymentGateways\Models\Payment;
        use Arca\PaymentGateways\ValueObjects\ChargeIntent;
        use Arca\PaymentGateways\ValueObjects\ChargeResult;

        class {$studly}Driver extends AbstractDriver implements PaymentGateway
        {
            public function name(): string
            {
                return '{$lower}';
            }

            public function charge(Payment \$payment, ChargeIntent \$intent): ChargeResult
            {
                // TODO: Implement charge logic
                // 1. Call the gateway API to create a payment session
                // 2. Store the external ID: \$payment->external_id = ...
                // 3. Return a ChargeResult with redirect URL or status

                return new ChargeResult(
                    status: PaymentStatus::Pending,
                    redirectUrl: 'https://gateway.example.com/pay',
                );
            }

            public function query(Payment \$payment): ChargeResult
            {
                // TODO: Implement query logic
                // 1. Call the gateway API to check payment status
                // 2. Return a ChargeResult with the current status

                return new ChargeResult(
                    status: \$payment->status,
                    externalId: \$payment->external_id,
                );
            }

            protected function baseUrl(): string
            {
                return \$this->isSandbox()
                    ? 'https://sandbox.example.com/api'
                    : 'https://api.example.com';
            }
        }

        PHP;
    }
}
