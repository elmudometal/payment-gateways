<?php

namespace Arca\PaymentGateways\Console\Commands;

use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\Exceptions\GatewayException;
use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\Services\PaymentService;
use Illuminate\Console\Command;

/**
 * Asks the gateways for the status of pending payments, for notifications that never arrived.
 * Schedule it, e.g. every fifteen minutes.
 */
class SyncPaymentsCommand extends Command
{
    protected $signature = 'payment-gateways:sync
        {payment? : Uuid of a single payment to sync}
        {--gateway= : Only payments of this gateway}
        {--older-than=10 : Only payments created at least this many minutes ago}
        {--max-age=72 : Skip payments created more than this many hours ago}';

    protected $description = 'Sync pending payments with their gateway';

    public function handle(PaymentService $payments): int
    {
        $query = Payment::query()->whereNotNull('external_id');

        if ($uuid = $this->argument('payment')) {
            $query->where('uuid', $uuid);
        } else {
            $query->where('status', PaymentStatus::Pending)
                ->where('created_at', '<=', now()->subMinutes((int) $this->option('older-than')))
                ->where('created_at', '>=', now()->subHours((int) $this->option('max-age')))
                ->when($this->option('gateway'), fn ($q, $gateway) => $q->where('gateway', $gateway));
        }

        $synced = 0;
        $failed = 0;

        $query->lazyById()->each(function (Payment $payment) use ($payments, &$synced, &$failed): void {
            try {
                $before = $payment->status;
                $payments->sync($payment);
                $synced++;

                if ($payment->status !== $before) {
                    $this->line("{$payment->uuid} [{$payment->gateway}] {$before->value} -> {$payment->status->value}");
                }
            } catch (GatewayException $e) {
                $failed++;
                $this->warn("{$payment->uuid} [{$payment->gateway}] {$e->getMessage()}");
            }
        });

        $this->components->info("Synced {$synced} payment(s), {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
