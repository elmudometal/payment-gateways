<?php

namespace Arca\PaymentGateways\Commands;

use Illuminate\Console\Command;

class PaymentGatewaysCommand extends Command
{
    public $signature = 'payment-gateways';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
