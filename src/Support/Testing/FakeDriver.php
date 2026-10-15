<?php

namespace Arca\PaymentGateways\Support\Testing;

use Arca\PaymentGateways\Contracts\PaymentGateway;
use Arca\PaymentGateways\Contracts\SupportsRefunds;
use Arca\PaymentGateways\Contracts\SupportsWebhooks;
use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\ValueObjects\ChargeIntent;
use Arca\PaymentGateways\ValueObjects\ChargeResult;
use Arca\PaymentGateways\ValueObjects\Money;
use Arca\PaymentGateways\ValueObjects\RefundResult;
use Illuminate\Http\Request;
use PHPUnit\Framework\Assert;

class FakeDriver implements PaymentGateway, SupportsRefunds, SupportsWebhooks
{
    /** @var list<array{payment: Payment, intent: ChargeIntent}> */
    protected array $charges = [];

    /** @var list<array{payment: Payment, amount: ?Money}> */
    protected array $refunds = [];

    /** @var list<Payment> */
    protected array $queries = [];

    /** @var list<Request> */
    protected array $webhooks = [];

    protected bool $webhookIsValid = true;

    protected ChargeResult $chargeResult;

    protected RefundResult $refundResult;

    public function __construct()
    {
        $this->chargeResult = new ChargeResult(
            status: PaymentStatus::Paid,
            externalId: 'fake-external-id',
            authorizationCode: 'fake-auth-code',
        );

        $this->refundResult = new RefundResult(
            status: PaymentStatus::Refunded,
            refundId: 'fake-refund-id',
        );
    }

    public function name(): string
    {
        return 'fake';
    }

    public function respondWith(ChargeResult $result): self
    {
        $this->chargeResult = $result;

        return $this;
    }

    public function respondRefundWith(RefundResult $result): self
    {
        $this->refundResult = $result;

        return $this;
    }

    public function charge(Payment $payment, ChargeIntent $intent): ChargeResult
    {
        $this->charges[] = ['payment' => $payment, 'intent' => $intent];

        return $this->chargeResult;
    }

    public function query(Payment $payment): ChargeResult
    {
        $this->queries[] = $payment;

        return $this->chargeResult;
    }

    public function refund(Payment $payment, ?Money $amount = null): RefundResult
    {
        $this->refunds[] = ['payment' => $payment, 'amount' => $amount];

        return $this->refundResult;
    }

    public function rejectWebhooks(): self
    {
        $this->webhookIsValid = false;

        return $this;
    }

    public function verifyWebhook(Request $request): bool
    {
        return $this->webhookIsValid;
    }

    public function handleWebhook(Request $request): void
    {
        $this->webhooks[] = $request;
    }

    public function assertWebhookHandled(int $times = 1): self
    {
        Assert::assertCount($times, $this->webhooks, "Expected {$times} webhook(s), got ".count($this->webhooks));

        return $this;
    }

    public function assertCharged(int $times = 1): self
    {
        Assert::assertCount($times, $this->charges, "Expected {$times} charge(s), got ".count($this->charges));

        return $this;
    }

    public function assertNotCharged(): self
    {
        Assert::assertEmpty($this->charges, 'Expected no charges, got '.count($this->charges));

        return $this;
    }

    public function assertRefunded(int $times = 1): self
    {
        Assert::assertCount($times, $this->refunds, "Expected {$times} refund(s), got ".count($this->refunds));

        return $this;
    }

    public function assertQueried(int $times = 1): self
    {
        Assert::assertCount($times, $this->queries, "Expected {$times} query(ies), got ".count($this->queries));

        return $this;
    }
}
