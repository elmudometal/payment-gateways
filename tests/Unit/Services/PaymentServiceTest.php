<?php

use Arca\PaymentGateways\Enums\Currency;
use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\Events\PaymentApproved;
use Arca\PaymentGateways\Events\PaymentInitiated;
use Arca\PaymentGateways\Events\PaymentRefunded;
use Arca\PaymentGateways\Events\PaymentRejected;
use Arca\PaymentGateways\Events\PaymentStatusChanged;
use Arca\PaymentGateways\Exceptions\GatewayConfigurationException;
use Arca\PaymentGateways\Exceptions\GatewayException;
use Arca\PaymentGateways\Exceptions\InvalidWebhookSignatureException;
use Arca\PaymentGateways\Exceptions\PaymentVerificationException;
use Arca\PaymentGateways\Exceptions\UnsupportedFeatureException;
use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\Services\PaymentService;
use Arca\PaymentGateways\ValueObjects\ChargeResult;
use Arca\PaymentGateways\ValueObjects\Customer;
use Arca\PaymentGateways\ValueObjects\Money;
use Arca\PaymentGateways\ValueObjects\RefundResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->service = app(PaymentService::class);
    $this->fake = $this->service->fake('webpay');
});

function pendingPayment(int $amount = 15000): Payment
{
    $payment = app(PaymentService::class)->create('webpay', Money::CLP($amount), 'Order #1');
    app(PaymentService::class)->charge($payment);

    return $payment;
}

it('creates a pending payment', function () {
    $payment = $this->service->create(
        gateway: 'webpay',
        money: Money::CLP(15000),
        description: 'Order #1',
        customer: new Customer(name: 'Ana', email: 'ana@example.com'),
        metadata: ['order_id' => 1],
    );

    expect($payment->exists)->toBeTrue()
        ->and($payment->uuid)->toBeUuid()
        ->and($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->money()->equals(Money::CLP(15000)))->toBeTrue()
        ->and($payment->customer()?->email)->toBe('ana@example.com')
        ->and($payment->metadata['order_id'])->toBe(1)
        ->and($payment->checkoutUrl())->toEndWith('/payments/'.$payment->uuid);
});

it('refuses gateways that are not configured', function () {
    $this->service->create('unknown', Money::CLP(1000));
})->throws(GatewayConfigurationException::class);

it('charges with the package urls and stores the checkout id', function () {
    Event::fake([PaymentInitiated::class]);

    $payment = pendingPayment();

    expect($payment->external_id)->toBe('fake-'.$payment->uuid);
    Event::assertDispatched(PaymentInitiated::class);
});

it('does not charge a payment twice once settled', function () {
    $payment = pendingPayment();
    $this->service->complete($payment, new Request);

    $this->service->charge($payment);
})->throws(GatewayException::class, 'cannot be charged again');

it('applies the result of the buyer coming back and dispatches events', function () {
    Event::fake([PaymentStatusChanged::class, PaymentApproved::class]);
    $payment = pendingPayment();

    $this->service->complete($payment, new Request);

    expect($payment->status)->toBe(PaymentStatus::Paid)
        ->and($payment->paid_at)->not->toBeNull()
        ->and($payment->transaction_id)->toBe('fake-transaction-id')
        ->and($payment->authorization_code)->toBe('fake-auth-code');

    Event::assertDispatched(PaymentStatusChanged::class, fn ($e) => $e->from === PaymentStatus::Pending && $e->to === PaymentStatus::Paid);
    Event::assertDispatched(PaymentApproved::class, 1);
});

it('does not call the gateway again for a settled payment', function () {
    $payment = pendingPayment();
    $this->service->complete($payment, new Request);
    $this->service->complete($payment, new Request);

    $this->fake->assertCompleted(1);
});

it('rejects a result for another reference', function () {
    $payment = pendingPayment();
    $this->fake->completeWith(new ChargeResult(status: PaymentStatus::Paid, reference: 'another-order'));

    expect(fn () => $this->service->complete($payment, new Request))
        ->toThrow(PaymentVerificationException::class, 'Reference mismatch');

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('rejects a payment approved for less than its amount', function () {
    $payment = pendingPayment(15000);
    $this->fake->completeWith(new ChargeResult(status: PaymentStatus::Paid, reference: $payment->uuid, amount: Money::CLP(1000)));

    expect(fn () => $this->service->complete($payment, new Request))
        ->toThrow(PaymentVerificationException::class, 'Amount mismatch');

    expect($payment->fresh()->isPending())->toBeTrue();
});

it('rejects a payment approved in another currency', function () {
    $payment = pendingPayment(15000);
    $this->fake->completeWith(new ChargeResult(status: PaymentStatus::Paid, amount: Money::of(15000, Currency::USD)));

    $this->service->complete($payment, new Request);
})->throws(PaymentVerificationException::class);

it('accepts a matching reference and amount', function () {
    $payment = pendingPayment(15000);
    $this->fake->completeWith(new ChargeResult(status: PaymentStatus::Paid, reference: $payment->uuid, amount: Money::CLP(15000)));

    $this->service->complete($payment, new Request);

    expect($payment->isPaid())->toBeTrue();
});

it('ignores out of order notifications for a paid payment', function () {
    Event::fake([PaymentRejected::class]);
    $payment = pendingPayment();
    $this->service->complete($payment, new Request);
    $voucher = $payment->voucher();

    $this->fake->completeWith(new ChargeResult(status: PaymentStatus::Failed, raw: ['stale' => true]));
    $changed = $this->service->apply($payment, $this->fake->query($payment));

    expect($changed)->toBeFalse()
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($payment->fresh()->voucher())->toBe($voucher);
    Event::assertNotDispatched(PaymentRejected::class);
});

it('lets a rejected payment be approved later', function () {
    $payment = pendingPayment();
    $this->fake->completeWith(new ChargeResult(status: PaymentStatus::Failed, message: 'Declined'));
    $this->service->complete($payment, new Request);

    expect($payment->isFailed())->toBeTrue()
        ->and($payment->failureMessage())->toBe('Declined');

    $this->service->apply($payment, new ChargeResult(status: PaymentStatus::Paid));

    expect($payment->isPaid())->toBeTrue();
});

it('syncs the status from the gateway', function () {
    $payment = pendingPayment();

    $this->service->sync($payment);

    $this->fake->assertQueried();
    expect($payment->isPaid())->toBeTrue();
});

it('handles verified webhooks', function () {
    $payment = pendingPayment();

    $updated = $this->service->handleWebhook('webpay', Request::create('/', 'POST', ['payment' => $payment->uuid]));

    expect($updated?->is($payment))->toBeTrue()
        ->and($payment->fresh()->isPaid())->toBeTrue();
});

it('refuses webhooks with an invalid signature', function () {
    $this->fake->rejectWebhooks();

    $this->service->handleWebhook('webpay', Request::create('/', 'POST'));
})->throws(InvalidWebhookSignatureException::class);

it('refuses webhooks for a payment of another gateway', function () {
    $payment = pendingPayment();
    $this->service->fake('flow');

    $this->service->handleWebhook('flow', Request::create('/', 'POST', ['payment' => $payment->uuid]));
})->throws(PaymentVerificationException::class);

it('refunds partially and then fully', function () {
    Event::fake([PaymentRefunded::class]);
    $payment = pendingPayment(10000);
    $this->service->complete($payment, new Request);

    $this->service->refund($payment, Money::CLP(4000));

    expect($payment->isPaid())->toBeTrue()
        ->and($payment->metadata['refunds'])->toHaveCount(1);
    Event::assertNotDispatched(PaymentRefunded::class);

    $this->service->refund($payment, Money::CLP(6000));

    expect($payment->isRefunded())->toBeTrue();
    Event::assertDispatched(PaymentRefunded::class, 1);
});

it('refuses to refund more than what is left', function () {
    $payment = pendingPayment(10000);
    $this->service->complete($payment, new Request);
    $this->service->refund($payment, Money::CLP(4000));

    $this->service->refund($payment, Money::CLP(6001));
})->throws(GatewayException::class, 'Invalid refund amount');

it('only refunds paid payments', function () {
    $this->service->refund(pendingPayment());
})->throws(GatewayException::class, 'only paid payments');

it('keeps the payment paid when the gateway refuses the refund', function () {
    $payment = pendingPayment();
    $this->service->complete($payment, new Request);
    $this->fake->respondRefundWith(new RefundResult(status: PaymentStatus::Paid, message: 'Too late'));

    $result = $this->service->refund($payment);

    expect($result->isRefunded())->toBeFalse()
        ->and($payment->fresh()->isPaid())->toBeTrue();
});

it('reports gateways without refunds', function () {
    $payment = Payment::factory()->gateway('niubiz')->paid()->create();

    $this->service->refund($payment);
})->throws(UnsupportedFeatureException::class);
