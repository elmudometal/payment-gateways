<?php

namespace Arca\PaymentGateways\Services;

use Arca\PaymentGateways\Contracts\PaymentGateway;
use Arca\PaymentGateways\Contracts\SupportsRefunds;
use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\Events\PaymentApproved;
use Arca\PaymentGateways\Events\PaymentInitiated;
use Arca\PaymentGateways\Events\PaymentRefunded;
use Arca\PaymentGateways\Events\PaymentRejected;
use Arca\PaymentGateways\Exceptions\UnsupportedFeatureException;
use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\Support\PaymentManager;
use Arca\PaymentGateways\ValueObjects\ChargeIntent;
use Arca\PaymentGateways\ValueObjects\ChargeResult;
use Arca\PaymentGateways\ValueObjects\Money;
use Arca\PaymentGateways\ValueObjects\RefundResult;
use Illuminate\Database\Eloquent\Model;

class PaymentService
{
    public function __construct(
        protected readonly PaymentManager $manager,
    ) {}

    public function create(
        string $gateway,
        ChargeIntent $intent,
        ?Model $payable = null,
    ): Payment {
        $payment = new Payment([
            'gateway' => $gateway,
            'amount' => $intent->money->amount,
            'currency' => $intent->money->currency->value,
            'description' => $intent->description,
            'metadata' => $intent->metadata,
        ]);

        if ($payable) {
            $payment->payable()->associate($payable);
        }

        $payment->save();

        return $payment;
    }

    public function charge(Payment $payment, ChargeIntent $intent): ChargeResult
    {
        $driver = $this->resolveDriver($payment->gateway);

        PaymentInitiated::dispatch($payment);

        $result = $driver->charge($payment, $intent);

        $this->applyResult($payment, $result);

        return $result;
    }

    public function query(Payment $payment): ChargeResult
    {
        $driver = $this->resolveDriver($payment->gateway);
        $result = $driver->query($payment);

        $this->applyResult($payment, $result);

        return $result;
    }

    public function refund(Payment $payment, ?Money $amount = null): RefundResult
    {
        $driver = $this->resolveDriver($payment->gateway);

        if (! $driver instanceof SupportsRefunds) {
            throw UnsupportedFeatureException::forFeature('refunds', $payment->gateway);
        }

        $result = $driver->refund($payment, $amount);

        if ($result->isRefunded()) {
            $payment->status = PaymentStatus::Refunded;
            $payment->save();

            PaymentRefunded::dispatch($payment);
        }

        return $result;
    }

    protected function applyResult(Payment $payment, ChargeResult $result): void
    {
        $previousStatus = $payment->status;

        $payment->status = $result->status;
        $payment->external_id = $result->externalId ?? $payment->external_id;
        $payment->authorization_code = $result->authorizationCode ?? $payment->authorization_code;

        if ($result->raw) {
            $payment->metadata = array_merge($payment->metadata ?? [], ['voucher' => $result->raw]);
        }

        if ($result->isApproved() && ! $payment->paid_at) {
            $payment->paid_at = now();
        }

        $payment->save();

        if ($previousStatus !== $payment->status) {
            match ($payment->status) {
                PaymentStatus::Paid => PaymentApproved::dispatch($payment),
                PaymentStatus::Failed => PaymentRejected::dispatch($payment),
                default => null,
            };
        }
    }

    protected function resolveDriver(string $gateway): PaymentGateway
    {
        return $this->manager->driver($gateway);
    }
}
