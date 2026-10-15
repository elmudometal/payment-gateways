<?php

use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\Facades\PaymentGateways;
use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\ValueObjects\Customer;
use Arca\PaymentGateways\ValueObjects\Money;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

const MP = 'https://api.mercadopago.com';

function mpPayment(array $overrides = []): array
{
    return $overrides + [
        'id' => 1234567890,
        'status' => 'approved',
        'status_detail' => 'accredited',
        'external_reference' => 'set-me',
        'transaction_amount' => 15000,
        'currency_id' => 'CLP',
        'payment_method_id' => 'visa',
        'payment_type_id' => 'credit_card',
        'authorization_code' => '301299',
        'payer' => ['email' => 'buyer@example.com'],
    ];
}

function mpSignature(string $dataId, string $requestId, int $ts, string $secret = 'mp-secret'): string
{
    $hash = hash_hmac('sha256', "id:{$dataId};request-id:{$requestId};ts:{$ts};", $secret);

    return "ts={$ts},v1={$hash}";
}

function startMpCheckout(int $amount = 15000): Payment
{
    Http::fake([MP.'/checkout/preferences' => Http::response(['id' => 'pref-1', 'init_point' => 'https://www.mercadopago.cl/checkout/v1/redirect?pref_id=pref-1'])]);

    $payment = PaymentGateways::create('mercadopago', Money::CLP($amount), 'Order #1', customer: new Customer(email: 'buyer@example.com'));
    test()->get($payment->checkoutUrl())->assertRedirect('https://www.mercadopago.cl/checkout/v1/redirect?pref_id=pref-1');

    return $payment->refresh();
}

it('creates a preference with the payment reference', function () {
    $payment = startMpCheckout();

    expect($payment->external_id)->toBe('pref-1');

    Http::assertSent(function (HttpRequest $request) use ($payment) {
        return $request->url() === MP.'/checkout/preferences'
            && $request->hasHeader('Authorization', 'Bearer TEST-token')
            && $request->hasHeader('X-Idempotency-Key', 'preference-'.$payment->uuid)
            && $request['external_reference'] === $payment->uuid
            && $request['items'][0]['unit_price'] === 15000
            && $request['items'][0]['currency_id'] === 'CLP'
            && $request['payer']['email'] === 'buyer@example.com'
            && $request['back_urls']['success'] === route('payments.commit', $payment);
    });
});

it('leaves auto_return and notification_url out on a local host', function () {
    startMpCheckout();

    Http::assertSent(fn (HttpRequest $request) => ! isset($request['auto_return']) && ! isset($request['notification_url']));
});

it('sends auto_return and notification_url on a public https host', function () {
    URL::forceRootUrl('https://shop.cl');
    URL::forceScheme('https');

    startMpCheckout();

    Http::assertSent(fn (HttpRequest $request) => $request['auto_return'] === 'approved'
        && $request['notification_url'] === 'https://shop.cl/payments/webhooks/mercadopago?source_news=webhooks');
});

it('approves the payment when the buyer comes back', function () {
    $payment = startMpCheckout();
    Http::fake([MP.'/v1/payments/1234567890' => Http::response(mpPayment(['external_reference' => $payment->uuid]))]);

    $this->get(route('payments.commit', $payment).'?payment_id=1234567890&status=approved')
        ->assertRedirect(route('payments.success', $payment));

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Paid)
        ->and($payment->transaction_id)->toBe('1234567890')
        ->and($payment->authorization_code)->toBe('301299')
        ->and($payment->voucher())->not->toHaveKey('payer')
        ->and($payment->voucher()['payment_method_id'])->toBe('visa');
});

it('never trusts the status in the query string', function () {
    $payment = startMpCheckout();
    Http::fake([MP.'/v1/payments/1234567890' => Http::response(mpPayment(['external_reference' => $payment->uuid, 'status' => 'rejected', 'status_detail' => 'cc_rejected_insufficient_amount']))]);

    $this->get(route('payments.commit', $payment).'?payment_id=1234567890&status=approved')
        ->assertRedirect(route('payments.failed', $payment));

    expect($payment->fresh()->failureMessage())->toBe(__('payment-gateways::mercadopago.cc_rejected_insufficient_amount'));
});

it('refuses a payment of another order', function () {
    $payment = startMpCheckout();
    Http::fake([MP.'/v1/payments/1234567890' => Http::response(mpPayment(['external_reference' => 'someone-else']))]);

    $this->get(route('payments.commit', $payment).'?payment_id=1234567890')->assertStatus(502);

    expect($payment->fresh()->isPending())->toBeTrue();
});

it('refuses a payment approved for less', function () {
    $payment = startMpCheckout(15000);
    Http::fake([MP.'/v1/payments/1234567890' => Http::response(mpPayment(['external_reference' => $payment->uuid, 'transaction_amount' => 10]))]);

    $this->get(route('payments.commit', $payment).'?payment_id=1234567890')->assertStatus(502);

    expect($payment->fresh()->isPending())->toBeTrue();
});

it('keeps cash payments pending', function () {
    $payment = startMpCheckout();
    Http::fake([MP.'/v1/payments/1234567890' => Http::response(mpPayment(['external_reference' => $payment->uuid, 'status' => 'pending', 'status_detail' => 'pending_waiting_payment']))]);

    $this->get(route('payments.commit', $payment).'?payment_id=1234567890')
        ->assertRedirect(route('payments.pending', $payment));

    // Back to the checkout URL: no new preference, the buyer already has a ticket.
    $this->get($payment->checkoutUrl())->assertRedirect(route('payments.pending', $payment));
});

it('fails the payment when the buyer leaves without paying', function () {
    $payment = startMpCheckout();
    Http::fake([MP.'/v1/payments/search*' => Http::response(['results' => []])]);

    $this->get(route('payments.commit', $payment).'?payment_id=null&status=null')
        ->assertRedirect(route('payments.failed', $payment));
});

it('picks the approved attempt when searching payments', function () {
    $payment = startMpCheckout();
    Http::fake([MP.'/v1/payments/search*' => Http::response(['results' => [
        mpPayment(['id' => 2, 'status' => 'rejected', 'external_reference' => $payment->uuid]),
        mpPayment(['id' => 1, 'status' => 'approved', 'external_reference' => $payment->uuid]),
    ]])]);

    PaymentGateways::sync($payment);

    expect($payment->status)->toBe(PaymentStatus::Paid)
        ->and($payment->transaction_id)->toBe('1');
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'external_reference='.$payment->uuid));
});

it('maps Mercado Pago statuses', function (string $mpStatus, PaymentStatus $expected) {
    Http::fake([MP.'/v1/payments/1' => Http::response(mpPayment(['id' => 1, 'status' => $mpStatus]))]);

    expect(PaymentGateways::driver('mercadopago')->getPayment(1)->status)->toBe($expected);
})->with([
    ['approved', PaymentStatus::Paid],
    ['authorized', PaymentStatus::Authorized],
    ['pending', PaymentStatus::Pending],
    ['in_process', PaymentStatus::Pending],
    ['in_mediation', PaymentStatus::Pending],
    ['rejected', PaymentStatus::Failed],
    ['cancelled', PaymentStatus::Failed],
    ['refunded', PaymentStatus::Refunded],
    ['charged_back', PaymentStatus::Reversed],
]);

it('processes a signed webhook', function () {
    $payment = startMpCheckout();
    Http::fake([MP.'/v1/payments/1234567890' => Http::response(mpPayment(['external_reference' => $payment->uuid]))]);
    $ts = now()->getTimestamp();

    $this->withHeaders([
        'x-signature' => mpSignature('1234567890', 'req-1', $ts),
        'x-request-id' => 'req-1',
    ])->postJson(route('payments.webhook', 'mercadopago').'?data.id=1234567890&type=payment', [
        'type' => 'payment',
        'data' => ['id' => '1234567890'],
    ])->assertOk()->assertJson(['message' => 'ok']);

    expect($payment->fresh()->isPaid())->toBeTrue();
});

it('refuses a webhook with a wrong signature', function () {
    $this->withHeaders([
        'x-signature' => mpSignature('1234567890', 'req-1', now()->getTimestamp(), 'wrong-secret'),
        'x-request-id' => 'req-1',
    ])->postJson(route('payments.webhook', 'mercadopago').'?data.id=1234567890&type=payment')
        ->assertUnauthorized();

    Http::assertNothingSent();
});

it('refuses old signatures when a tolerance is configured', function () {
    config()->set('payment-gateways.gateways.mercadopago.webhook_tolerance', 300);
    $ts = now()->subHour()->getTimestamp();

    $this->withHeaders([
        'x-signature' => mpSignature('1234567890', 'req-1', $ts),
        'x-request-id' => 'req-1',
    ])->postJson(route('payments.webhook', 'mercadopago').'?data.id=1234567890&type=payment')
        ->assertUnauthorized();
});

it('requires the webhook secret in production', function () {
    config()->set('payment-gateways.gateways.mercadopago.environment', 'production');
    config()->set('payment-gateways.gateways.mercadopago.webhook_secret', null);

    $this->postJson(route('payments.webhook', 'mercadopago').'?data.id=1234567890&type=payment')
        ->assertUnauthorized();
});

it('accepts unsigned webhooks in sandbox without a secret', function () {
    config()->set('payment-gateways.gateways.mercadopago.webhook_secret', null);
    $payment = startMpCheckout();
    Http::fake([MP.'/v1/payments/1234567890' => Http::response(mpPayment(['external_reference' => $payment->uuid]))]);

    $this->postJson(route('payments.webhook', 'mercadopago').'?data.id=1234567890&type=payment')->assertOk();

    expect($payment->fresh()->isPaid())->toBeTrue();
});

it('ignores other notification topics', function () {
    $ts = now()->getTimestamp();

    $this->withHeaders(['x-signature' => mpSignature('99', 'req-1', $ts), 'x-request-id' => 'req-1'])
        ->postJson(route('payments.webhook', 'mercadopago').'?data.id=99&type=merchant_order')
        ->assertOk()
        ->assertJson(['message' => 'ignored']);

    Http::assertNothingSent();
});

it('refunds fully and partially with idempotency keys', function () {
    $payment = Payment::factory()->gateway('mercadopago')->paid()->create([
        'amount' => 15000,
        'transaction_id' => '1234567890',
    ]);
    Http::fake([MP.'/v1/payments/1234567890/refunds' => Http::sequence()
        ->push(['id' => 1, 'status' => 'approved', 'amount' => 5000], 201)
        ->push(['id' => 2, 'status' => 'approved', 'amount' => 10000], 201)]);

    PaymentGateways::refund($payment, Money::CLP(5000));
    expect($payment->isPaid())->toBeTrue();

    PaymentGateways::refund($payment);
    expect($payment->isRefunded())->toBeTrue();

    Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('X-Idempotency-Key', "refund-{$payment->uuid}-0") && $r['amount'] === 5000);
    Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('X-Idempotency-Key', "refund-{$payment->uuid}-1") && $r->data() === []);
});
