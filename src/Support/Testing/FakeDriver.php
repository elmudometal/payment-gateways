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
use Arca\PaymentGateways\ValueObjects\WebhookResult;
use Illuminate\Http\Request;
use PHPUnit\Framework\Assert;

/**
 * A gateway that records calls instead of talking to an API.
 *
 * By default charge() redirects to a fake checkout, complete(), query() and webhooks approve the
 * payment. Change the answers with respondWith(), completeWith() and respondRefundWith().
 * Webhooks find the payment by the `payment` uuid in the request.
 */
class FakeDriver implements PaymentGateway, SupportsRefunds, SupportsWebhooks
{
    /** @var list<array{payment: Payment, intent: ChargeIntent}> */
    protected array $charges = [];

    /** @var list<Payment> */
    protected array $completions = [];

    /** @var list<array{payment: Payment, amount: ?Money}> */
    protected array $refunds = [];

    /** @var list<Payment> */
    protected array $queries = [];

    /** @var list<Request> */
    protected array $webhooks = [];

    protected bool $webhookIsValid = true;

    protected ?ChargeResult $chargeResult = null;

    protected ChargeResult $completeResult;

    protected RefundResult $refundResult;

    public function __construct(
        protected string $name = 'fake',
    ) {
        $this->completeResult = new ChargeResult(
            status: PaymentStatus::Paid,
            transactionId: 'fake-transaction-id',
            authorizationCode: 'fake-auth-code',
        );

        $this->refundResult = new RefundResult(
            status: PaymentStatus::Refunded,
            refundId: 'fake-refund-id',
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    public function reference(Payment $payment): string
    {
        return $payment->uuid;
    }

    /**
     * The answer of charge(). Defaults to a pending redirect to a fake checkout.
     */
    public function respondWith(ChargeResult $result): self
    {
        $this->chargeResult = $result;

        return $this;
    }

    /**
     * The answer of complete(), query() and webhooks. Defaults to paid.
     */
    public function completeWith(ChargeResult $result): self
    {
        $this->completeResult = $result;

        return $this;
    }

    public function respondRefundWith(RefundResult $result): self
    {
        $this->refundResult = $result;

        return $this;
    }

    public function rejectWebhooks(): self
    {
        $this->webhookIsValid = false;

        return $this;
    }

    public function charge(Payment $payment, ChargeIntent $intent): ChargeResult
    {
        $this->charges[] = ['payment' => $payment, 'intent' => $intent];

        return $this->chargeResult ?? new ChargeResult(
            status: PaymentStatus::Pending,
            externalId: 'fake-'.$payment->uuid,
            redirectUrl: 'https://checkout.fake/'.$payment->uuid,
        );
    }

    public function complete(Payment $payment, Request $request): ChargeResult
    {
        $this->completions[] = $payment;

        return $this->completeResult;
    }

    public function query(Payment $payment): ChargeResult
    {
        $this->queries[] = $payment;

        return $this->completeResult;
    }

    public function refund(Payment $payment, ?Money $amount = null): RefundResult
    {
        $this->refunds[] = ['payment' => $payment, 'amount' => $amount];

        return $this->refundResult;
    }

    public function verifyWebhook(Request $request): bool
    {
        return $this->webhookIsValid;
    }

    public function handleWebhook(Request $request): ?WebhookResult
    {
        $this->webhooks[] = $request;

        $payment = Payment::query()->where('uuid', (string) $request->input('payment'))->first();

        return $payment ? new WebhookResult($payment, $this->completeResult) : null;
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

    public function assertCompleted(int $times = 1): self
    {
        Assert::assertCount($times, $this->completions, "Expected {$times} completion(s), got ".count($this->completions));

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

    public function assertWebhookHandled(int $times = 1): self
    {
        Assert::assertCount($times, $this->webhooks, "Expected {$times} webhook(s), got ".count($this->webhooks));

        return $this;
    }
}
