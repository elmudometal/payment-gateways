<?php

namespace Arca\PaymentGateways\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class MakePaymentDriverCommand extends Command
{
    protected $signature = 'make:payment-driver {name : The name of the payment driver (e.g. Khipu)}';

    protected $description = 'Scaffold a new payment gateway driver';

    public function handle(Filesystem $files): int
    {
        $name = (string) $this->argument('name');
        $studly = Str::studly($name);
        $lower = Str::lower($studly);

        $dir = app_path("PaymentDrivers/{$studly}");

        if ($files->isDirectory($dir)) {
            $this->error("Driver directory [{$dir}] already exists.");

            return self::FAILURE;
        }

        $files->makeDirectory($dir, 0755, true);

        $files->put("{$dir}/{$studly}Driver.php", $this->driverStub($studly, $lower));

        $this->components->info("Payment driver [{$studly}] created at [{$dir}].");
        $this->newLine();
        $this->line('  Register it in a service provider:');
        $this->newLine();
        $this->line("  <comment>PaymentGateways::extend('{$lower}', fn (\$app, array \$config) => new \\App\\PaymentDrivers\\{$studly}\\{$studly}Driver(\$config));</comment>");
        $this->newLine();
        $this->line('  Then add the gateway in <comment>config/payment-gateways.php</comment>:');
        $this->newLine();
        $this->line("  <comment>'{$lower}' => [</comment>");
        $this->line("  <comment>    'driver' => '{$lower}',</comment>");
        $this->line("  <comment>    'environment' => env('".Str::upper(Str::snake($studly))."_ENV'),</comment>");
        $this->line('  <comment>    // your credentials...</comment>');
        $this->line('  <comment>],</comment>');

        return self::SUCCESS;
    }

    protected function driverStub(string $studly, string $lower): string
    {
        $stub = <<<'PHP'
        <?php

        namespace App\PaymentDrivers\{{studly}};

        use Arca\PaymentGateways\Drivers\AbstractDriver;
        use Arca\PaymentGateways\Enums\PaymentStatus;
        use Arca\PaymentGateways\Models\Payment;
        use Arca\PaymentGateways\ValueObjects\ChargeIntent;
        use Arca\PaymentGateways\ValueObjects\ChargeResult;
        use Arca\PaymentGateways\ValueObjects\Money;
        use Illuminate\Http\Request;

        class {{studly}}Driver extends AbstractDriver
        {
            public function name(): string
            {
                return '{{lower}}';
            }

            public function charge(Payment $payment, ChargeIntent $intent): ChargeResult
            {
                // Create the checkout with $intent->money and $intent->urls (return and webhook URLs).
                // Send $this->reference($payment) as the order reference.
                $data = $this->http()->post('/checkouts', [
                    'reference' => $this->reference($payment),
                    'amount' => $intent->money->toDecimalString(),
                    'currency' => $intent->money->currency->value,
                    'return_url' => $intent->urls->returnUrl,
                ])->json();

                return new ChargeResult(
                    status: PaymentStatus::Pending,
                    externalId: $data['id'],
                    redirectUrl: $data['checkout_url'],
                );
            }

            public function complete(Payment $payment, Request $request): ChargeResult
            {
                // The buyer is back on the commit URL. Never trust the request: ask the API.
                return $this->query($payment);
            }

            public function query(Payment $payment): ChargeResult
            {
                $data = $this->http(retry: true)->get('/checkouts/'.$payment->external_id)->json();

                return new ChargeResult(
                    status: match ($data['status']) {
                        'paid' => PaymentStatus::Paid,
                        'rejected' => PaymentStatus::Failed,
                        default => PaymentStatus::Pending,
                    },
                    // The reported reference and amount are checked against the payment before it changes.
                    reference: $data['reference'],
                    amount: Money::fromDecimal($data['amount'], $data['currency']),
                    raw: $data,
                );
            }

            protected function baseUrl(): string
            {
                return $this->isSandbox()
                    ? 'https://sandbox.example.com/api'
                    : 'https://api.example.com';
            }
        }

        PHP;

        return strtr($stub, ['{{studly}}' => $studly, '{{lower}}' => $lower]);
    }
}
