<?php

use Arca\PaymentGateways\Enums\Currency;
use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\Facades\PaymentGateways;
use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\ValueObjects\Money;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

const PAYPAL = 'https://api-m.sandbox.paypal.com';

function paypalOrder(Payment $payment, string $status, ?array $capture = null, string $value = '19.99'): array
{
    $unit = [
        'reference_id' => $payment->uuid,
        'amount' => ['currency_code' => 'USD', 'value' => $value],
    ];

    if ($capture !== null) {
        $unit['payments']['captures'][] = $capture + [
            'id' => 'CAPTURE-1',
            'status' => 'COMPLETED',
            'amount' => ['currency_code' => 'USD', 'value' => $value],
        ];
    }

    return ['id' => 'ORDER-1', 'status' => $status, 'purchase_units' => [$unit], 'payer' => ['payer_id' => 'PAYER']];
}

function fakePaypal(array $routes = []): void
{
    Http::fake($routes + [
        PAYPAL.'/v1/oauth2/token' => Http::response(['access_token' => 'A21-token', 'expires_in' => 32400]),
        PAYPAL.'/v2/checkout/orders' => Http::response([
            'id' => 'ORDER-1',
            'status' => 'PAYER_ACTION_REQUIRED',
            'links' => [['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1']],
        ], 201),
    ]);
}

function startPaypalCheckout(): Payment
{
    fakePaypal();

    $payment = PaymentGateways::create('paypal', Money::of(1999, Currency::USD), 'Order #1');
    test()->get($payment->checkoutUrl())->assertRedirect('https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1');

    return $payment->refresh();
}

it('creates an order for the payment', function () {
    $payment = startPaypalCheckout();

    expect($payment->external_id)->toBe('ORDER-1');

    Http::assertSent(fn (HttpRequest $r) => $r->url() === PAYPAL.'/v2/checkout/orders'
        && $r->hasHeader('Authorization', 'Bearer A21-token')
        && $r->hasHeader('PayPal-Request-Id', 'order-'.$payment->uuid)
        && $r['purchase_units'][0]['reference_id'] === $payment->uuid
        && $r['purchase_units'][0]['amount'] === ['currency_code' => 'USD', 'value' => '19.99']
        && $r['payment_source']['paypal']['experience_context']['return_url'] === route('payments.commit', $payment));
});

it('captures the approved order when the buyer comes back', function () {
    $payment = startPaypalCheckout();
    fakePaypal([
        PAYPAL.'/v2/checkout/orders/ORDER-1/capture' => Http::response(paypalOrder($payment, 'COMPLETED', []), 201),
        PAYPAL.'/v2/checkout/orders/ORDER-1' => Http::response(paypalOrder($payment, 'APPROVED')),
    ]);

    $this->get(route('payments.commit', $payment).'?token=ORDER-1&PayerID=PAYER')
        ->assertRedirect(route('payments.success', $payment));

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Paid)
        ->and($payment->transaction_id)->toBe('CAPTURE-1');
});

it('refuses an order of another payment', function () {
    $payment = startPaypalCheckout();

    $this->get(route('payments.commit', $payment).'?token=ORDER-OTHER')->assertStatus(502);

    Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/capture'));
});

it('refuses a capture for a lower amount', function () {
    $payment = startPaypalCheckout();
    fakePaypal([
        PAYPAL.'/v2/checkout/orders/ORDER-1/capture' => Http::response(paypalOrder($payment, 'COMPLETED', [], '0.01'), 201),
        PAYPAL.'/v2/checkout/orders/ORDER-1' => Http::response(paypalOrder($payment, 'APPROVED')),
    ]);

    $this->get(route('payments.commit', $payment).'?token=ORDER-1')->assertStatus(502);

    expect($payment->fresh()->isPending())->toBeTrue();
});

it('fails a declined capture', function () {
    $payment = startPaypalCheckout();
    fakePaypal([
        PAYPAL.'/v2/checkout/orders/ORDER-1/capture' => Http::response(paypalOrder($payment, 'COMPLETED', ['status' => 'DECLINED', 'status_details' => ['reason' => 'DECLINED_BY_RISK_FRAUD_FILTERS']]), 201),
        PAYPAL.'/v2/checkout/orders/ORDER-1' => Http::response(paypalOrder($payment, 'APPROVED')),
    ]);

    $this->get(route('payments.commit', $payment).'?token=ORDER-1')->assertRedirect(route('payments.failed', $payment));

    expect($payment->fresh()->failureMessage())->toBe('DECLINED_BY_RISK_FRAUD_FILTERS');
});

it('fails the payment when the buyer cancels on PayPal', function () {
    $payment = startPaypalCheckout();
    fakePaypal([PAYPAL.'/v2/checkout/orders/ORDER-1' => Http::response(paypalOrder($payment, 'PAYER_ACTION_REQUIRED'))]);

    $this->get(route('payments.commit', $payment).'?cancelled=1&token=ORDER-1')
        ->assertRedirect(route('payments.failed', $payment));
});

it('reads the order when the capture already happened', function () {
    $payment = startPaypalCheckout();
    fakePaypal([
        PAYPAL.'/v2/checkout/orders/ORDER-1/capture' => Http::response(['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'ORDER_ALREADY_CAPTURED']]], 422),
        PAYPAL.'/v2/checkout/orders/ORDER-1' => Http::sequence()
            ->push(paypalOrder($payment, 'APPROVED'))
            ->push(paypalOrder($payment, 'COMPLETED', [])),
    ]);

    $this->get(route('payments.commit', $payment).'?token=ORDER-1')->assertRedirect(route('payments.success', $payment));
});

it('caches the OAuth token', function () {
    $payment = startPaypalCheckout();
    fakePaypal([PAYPAL.'/v2/checkout/orders/ORDER-1' => Http::response(paypalOrder($payment, 'PAYER_ACTION_REQUIRED'))]);

    PaymentGateways::sync($payment);
    PaymentGateways::sync($payment);

    Http::assertSentCount(2);
});

it('verifies webhooks with the PayPal API and captures approved orders', function () {
    $payment = startPaypalCheckout();
    fakePaypal([
        PAYPAL.'/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
        PAYPAL.'/v2/checkout/orders/ORDER-1/capture' => Http::response(paypalOrder($payment, 'COMPLETED', []), 201),
    ]);
    $event = '{"id":"WH-1","event_type":"CHECKOUT.ORDER.APPROVED","resource":{"id":"ORDER-1","amount":1.10}}';

    $this->call('POST', route('payments.webhook', 'paypal'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
        'HTTP_PAYPAL_CERT_URL' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT',
        'HTTP_PAYPAL_TRANSMISSION_ID' => 'tx-1',
        'HTTP_PAYPAL_TRANSMISSION_SIG' => 'sig',
        'HTTP_PAYPAL_TRANSMISSION_TIME' => '2026-10-15T10:00:00Z',
    ], $event)->assertOk();

    expect($payment->fresh()->isPaid())->toBeTrue();

    // The event is sent back untouched (1.10 would become 1.1 if re-encoded).
    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/verify-webhook-signature')
        && str_contains($r->body(), '"webhook_event":'.$event)
        && $r['webhook_id'] === 'WH-TEST');
});

it('refuses webhooks PayPal does not verify', function () {
    fakePaypal([PAYPAL.'/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'FAILURE'])]);

    $this->withHeaders([
        'paypal-auth-algo' => 'SHA256withRSA',
        'paypal-cert-url' => 'https://api.sandbox.paypal.com/cert',
        'paypal-transmission-id' => 'tx-1',
        'paypal-transmission-sig' => 'sig',
        'paypal-transmission-time' => '2026-10-15T10:00:00Z',
    ])->postJson(route('payments.webhook', 'paypal'), ['event_type' => 'PAYMENT.CAPTURE.COMPLETED'])
        ->assertUnauthorized();
});

it('refunds the capture', function () {
    $payment = Payment::factory()->gateway('paypal')->paid()->create([
        'amount' => 1999,
        'currency' => Currency::USD,
        'transaction_id' => 'CAPTURE-1',
    ]);
    fakePaypal([PAYPAL.'/v2/payments/captures/CAPTURE-1/refund' => Http::response(['id' => 'REFUND-1', 'status' => 'COMPLETED'], 201)]);

    PaymentGateways::refund($payment);

    expect($payment->isRefunded())->toBeTrue();
    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/captures/CAPTURE-1/refund') && $r->body() === '{}');
});
