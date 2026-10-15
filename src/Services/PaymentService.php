<?php

namespace Arca\PaymentGateways\Services;

use Arca\PaymentGateways\Contracts\PaymentGateway;
use Arca\PaymentGateways\Contracts\SupportsRefunds;
use Arca\PaymentGateways\Contracts\SupportsWebhooks;
use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\Events\PaymentApproved;
use Arca\PaymentGateways\Events\PaymentAuthorized;
use Arca\PaymentGateways\Events\PaymentInitiated;
use Arca\PaymentGateways\Events\PaymentRefunded;
use Arca\PaymentGateways\Events\PaymentRejected;
use Arca\PaymentGateways\Events\PaymentReversed;
use Arca\PaymentGateways\Events\PaymentStatusChanged;
use Arca\PaymentGateways\Events\WebhookReceived;
use Arca\PaymentGateways\Exceptions\GatewayException;
use Arca\PaymentGateways\Exceptions\InvalidWebhookSignatureException;
use Arca\PaymentGateways\Exceptions\PaymentVerificationException;
use Arca\PaymentGateways\Exceptions\UnsupportedFeatureException;
use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\Models\PaymentLog;
use Arca\PaymentGateways\Support\PaymentLogger;
use Arca\PaymentGateways\Support\PaymentManager;
use Arca\PaymentGateways\Support\Testing\FakeDriver;
use Arca\PaymentGateways\ValueObjects\ChargeIntent;
use Arca\PaymentGateways\ValueObjects\ChargeResult;
use Arca\PaymentGateways\ValueObjects\Customer;
use Arca\PaymentGateways\ValueObjects\Money;
use Arca\PaymentGateways\ValueObjects\RefundResult;
use Arca\PaymentGateways\ValueObjects\ReturnUrls;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentService
{
    public function __construct(
        protected readonly PaymentManager $manager,
        protected readonly PaymentLogger $logger = new PaymentLogger,
    ) {}

    /**
     * Creates a pending payment. Send the buyer to $payment->checkoutUrl() to pay it.
     *
     * @param  array<string, mixed>  $metadata  Your own data; the package never writes it
     */
    public function create(
        string $gateway,
        Money $money,
        ?string $description = null,
        ?Model $payable = null,
        ?Customer $customer = null,
        array $metadata = [],
        ?DateTimeInterface $expiresAt = null,
    ): Payment {
        $this->driver($gateway);

        $payment = new Payment([
            'gateway' => $gateway,
            'amount' => $money->amount,
            'currency' => $money->currency,
            'description' => $description,
            'customer' => $customer,
            'metadata' => $metadata === [] ? null : $metadata,
            'expires_at' => $expiresAt,
        ]);

        if ($payable) {
            $payment->payable()->associate($payable);
        }

        $payment->save();

        return $payment;
    }

    /**
     * Starts the checkout with the gateway.
     */
    public function charge(Payment $payment): ChargeResult
    {
        if (! $payment->isPending()) {
            throw new GatewayException(
                message: "Payment [{$payment->uuid}] is {$payment->status->value} and cannot be charged again.",
                gateway: $payment->gateway,
            );
        }

        $driver = $this->driver($payment->gateway);

        $intent = new ChargeIntent(
            money: $payment->money(),
            description: $payment->description ?: (string) config('app.name', 'Payment'),
            urls: ReturnUrls::forPayment($payment),
            customer: $payment->customer,
            metadata: $payment->metadata ?? [],
        );

        $result = $this->recorded($payment, $driver, PaymentLog::TYPE_CHARGE, fn () => $driver->charge($payment, $intent));

        $this->apply($payment, $result, $driver);

        if (! $result->isFailed()) {
            PaymentInitiated::dispatch($payment);
        }

        return $result;
    }

    /**
     * Handles the buyer coming back from the gateway. A payment that is no longer pending is not
     * sent to the gateway again (a refreshed page must not commit twice).
     */
    public function complete(Payment $payment, Request $request): ChargeResult
    {
        $this->logger->incoming($payment, $payment->gateway, PaymentLog::TYPE_RETURN, $request);

        if (! $payment->isPending()) {
            return $this->currentResult($payment);
        }

        $driver = $this->driver($payment->gateway);

        $result = $this->recorded($payment, $driver, PaymentLog::TYPE_COMPLETE, fn () => $driver->complete($payment, $request));

        $this->apply($payment, $result, $driver);

        return $result;
    }

    /**
     * Fetches the payment status from the gateway and applies it.
     */
    public function sync(Payment $payment): ChargeResult
    {
        $driver = $this->driver($payment->gateway);

        $result = $this->recorded($payment, $driver, PaymentLog::TYPE_QUERY, fn () => $driver->query($payment));

        $this->apply($payment, $result, $driver);

        return $result;
    }

    /**
     * Verifies and processes a gateway notification. Returns the payment it updated, if any.
     * Every notification is logged, including those refused or about unknown payments.
     */
    public function handleWebhook(string $gateway, Request $request): ?Payment
    {
        $driver = $this->driver($gateway);

        if (! $driver instanceof SupportsWebhooks) {
            throw UnsupportedFeatureException::forFeature('webhooks', $gateway);
        }

        $verified = $this->recorded(null, $driver, PaymentLog::TYPE_WEBHOOK, fn () => $driver->verifyWebhook($request), $gateway, $verification);
        $log = $this->logger->incoming(null, $gateway, PaymentLog::TYPE_WEBHOOK, $request, $verified);

        if (! $verified) {
            throw new InvalidWebhookSignatureException(
                message: "Invalid webhook signature for [{$gateway}].",
                gateway: $gateway,
            );
        }

        WebhookReceived::dispatch($gateway, $request->all());

        $webhook = $this->recorded(null, $driver, PaymentLog::TYPE_WEBHOOK, fn () => $driver->handleWebhook($request), $gateway, $resolved);

        if ($webhook === null) {
            return null;
        }

        $this->logger->attach($webhook->payment, $log?->getKey(), ...($verification ?? []), ...($resolved ?? []));

        if ($webhook->payment->gateway !== $gateway) {
            throw new PaymentVerificationException(
                message: "Payment [{$webhook->payment->uuid}] belongs to [{$webhook->payment->gateway}], not [{$gateway}].",
                gateway: $gateway,
            );
        }

        $this->apply($webhook->payment, $webhook->result, $driver);

        return $webhook->payment;
    }

    /**
     * Refunds a paid payment, fully (no amount) or partially. The payment becomes refunded once
     * the refunds cover the whole amount.
     */
    public function refund(Payment $payment, ?Money $amount = null): RefundResult
    {
        $driver = $this->driver($payment->gateway);

        if (! $driver instanceof SupportsRefunds) {
            throw UnsupportedFeatureException::forFeature('refunds', $payment->gateway);
        }

        if (! $payment->isPaid()) {
            throw new GatewayException(
                message: "Payment [{$payment->uuid}] is {$payment->status->value}; only paid payments can be refunded.",
                gateway: $payment->gateway,
            );
        }

        $remaining = $payment->amount - $payment->refunded_amount;

        if ($amount !== null && ($amount->currency !== $payment->currency || $amount->amount > $remaining || $amount->amount === 0)) {
            throw new GatewayException(
                message: "Invalid refund amount for payment [{$payment->uuid}]: at most {$remaining} {$payment->currency->value}.",
                gateway: $payment->gateway,
            );
        }

        $result = $this->recorded($payment, $driver, PaymentLog::TYPE_REFUND, fn () => $driver->refund($payment, $amount));

        if (! $result->isRefunded()) {
            return $result;
        }

        $refunded = $amount->amount ?? $remaining;

        DB::transaction(function () use ($payment, $refunded): void {
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());

            $locked->refunded_amount += $refunded;
            $full = $locked->refunded_amount >= $locked->amount;
            $from = $locked->status;

            if ($full) {
                $locked->status = PaymentStatus::Refunded;
            }

            $locked->save();

            $payment->setRawAttributes($locked->getAttributes(), true);

            if ($full) {
                $this->dispatchStatusEvents($payment, $from);
            }
        });

        return $result;
    }

    /**
     * Applies a gateway result to the payment: verifies it, then updates the status only when the
     * transition is allowed. Every status change goes through here.
     */
    public function apply(Payment $payment, ChargeResult $result, ?PaymentGateway $driver = null): bool
    {
        $driver ??= $this->driver($payment->gateway);

        return DB::transaction(function () use ($payment, $result, $driver): bool {
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());

            $this->verify($locked, $result, $driver);

            $from = $locked->status;
            $changed = $from->canTransitionTo($result->status);

            if (! $changed && $from !== $result->status) {
                Log::channel(config('payment-gateways.log_channel'))->info(
                    "[payment-gateways:{$locked->gateway}] Ignored {$from->value} -> {$result->status->value} for payment [{$locked->uuid}]",
                );

                $payment->setRawAttributes($locked->getAttributes(), true);

                return false;
            }

            $locked->external_id = $result->externalId ?? $locked->external_id;
            $locked->transaction_id = $result->transactionId ?? $locked->transaction_id;
            $locked->authorization_code = $result->authorizationCode ?? $locked->authorization_code;

            if ($result->receipt !== []) {
                $locked->receipt = array_filter($result->receipt, fn ($value) => $value !== null && $value !== '') ?: null;
            }

            if ($result->isFailed()) {
                $locked->failure_message = $result->message ?? $locked->failure_message;
            } elseif (in_array($result->status, [PaymentStatus::Paid, PaymentStatus::Authorized], true)) {
                $locked->failure_message = null;
            }

            if ($changed) {
                $locked->status = $result->status;

                if ($result->isApproved() && ! $locked->paid_at) {
                    $locked->paid_at = now();
                }
            }

            $locked->save();

            $payment->setRawAttributes($locked->getAttributes(), true);

            if ($changed) {
                $this->dispatchStatusEvents($payment, $from);
            }

            return $changed;
        });
    }

    public function driver(?string $gateway = null): PaymentGateway
    {
        return $this->manager->driver($gateway);
    }

    /**
     * @param  Closure(Container, array<string, mixed>): PaymentGateway  $callback
     */
    public function extend(string $gateway, Closure $callback): static
    {
        $this->manager->extend($gateway, $callback);

        return $this;
    }

    public function fake(?string $gateway = null): FakeDriver
    {
        return $this->manager->fake($gateway);
    }

    /**
     * Runs a driver operation and stores the API calls it made, also when it fails.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @param  list<int>|null  $logIds  Filled with the ids of the logs written
     * @return T
     */
    protected function recorded(?Payment $payment, PaymentGateway $driver, string $type, Closure $operation, ?string $gateway = null, ?array &$logIds = null): mixed
    {
        try {
            return $operation();
        } finally {
            $logIds = $this->logger->exchanges($payment, $gateway ?? $payment->gateway ?? $driver->name(), $type, $driver);
        }
    }

    /**
     * Rejects results whose reference, amount or currency differ from the local payment, so a
     * payment can never be approved with another order's (or a cheaper) gateway payment.
     */
    protected function verify(Payment $payment, ChargeResult $result, PaymentGateway $driver): void
    {
        if ($result->reference !== null && $result->reference !== $driver->reference($payment)) {
            throw new PaymentVerificationException(
                message: "Reference mismatch for payment [{$payment->uuid}]: gateway reported [{$result->reference}].",
                gateway: $payment->gateway,
                context: ['expected' => $driver->reference($payment), 'reported' => $result->reference],
            );
        }

        if ($result->amount === null || ! in_array($result->status, [PaymentStatus::Paid, PaymentStatus::Authorized], true)) {
            return;
        }

        if ($result->amount->currency !== $payment->currency || $result->amount->isLessThan($payment->money())) {
            throw new PaymentVerificationException(
                message: "Amount mismatch for payment [{$payment->uuid}]: gateway reported {$result->amount->toDecimalString()} {$result->amount->currency->value}, expected {$payment->money()->toDecimalString()} {$payment->currency->value}.",
                gateway: $payment->gateway,
                context: ['expected' => $payment->amount, 'reported' => $result->amount->amount],
            );
        }
    }

    protected function currentResult(Payment $payment): ChargeResult
    {
        return new ChargeResult(
            status: $payment->status,
            externalId: $payment->external_id,
            transactionId: $payment->transaction_id,
            authorizationCode: $payment->authorization_code,
            message: $payment->failureMessage(),
        );
    }

    protected function dispatchStatusEvents(Payment $payment, PaymentStatus $from): void
    {
        PaymentStatusChanged::dispatch($payment, $from, $payment->status);

        match ($payment->status) {
            PaymentStatus::Authorized => PaymentAuthorized::dispatch($payment),
            PaymentStatus::Paid => PaymentApproved::dispatch($payment),
            PaymentStatus::Failed => PaymentRejected::dispatch($payment),
            PaymentStatus::Refunded => PaymentRefunded::dispatch($payment),
            PaymentStatus::Reversed => PaymentReversed::dispatch($payment),
            PaymentStatus::Pending => null,
        };
    }
}
