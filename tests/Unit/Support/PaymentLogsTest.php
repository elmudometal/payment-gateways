<?php

use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\Exceptions\GatewayCommunicationException;
use Arca\PaymentGateways\Facades\PaymentGateways;
use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\Models\PaymentLog;
use Arca\PaymentGateways\ValueObjects\ChargeResult;
use Arca\PaymentGateways\ValueObjects\Customer;
use Arca\PaymentGateways\ValueObjects\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

const MP_API = 'https://api.mercadopago.com';

function mpCheckout(): Payment
{
    Http::fake([MP_API.'/checkout/preferences' => Http::response([
        'id' => 'pref-1',
        'init_point' => 'https://mp.test/pay',
        'collector_id' => 123,
        'some_future_field' => ['nested' => true],
    ], 201)]);

    $payment = PaymentGateways::create('mercadopago', Money::CLP(15000), 'Order #1', customer: new Customer(email: 'buyer@example.com'));
    PaymentGateways::charge($payment);

    return $payment;
}

it('stores every API call untouched, without credentials', function () {
    $payment = mpCheckout();

    $log = $payment->logs()->sole();

    expect($log->type)->toBe(PaymentLog::TYPE_CHARGE)
        ->and($log->direction)->toBe('out')
        ->and($log->method)->toBe('POST')
        ->and($log->url)->toBe(MP_API.'/checkout/preferences')
        ->and($log->status_code)->toBe(201)
        ->and($log->request['external_reference'])->toBe($payment->uuid)
        ->and($log->request['payer']['email'])->toBe('buyer@example.com')
        // Fields the package does not use are kept: the answer is stored as received.
        ->and($log->response['some_future_field'])->toBe(['nested' => true])
        ->and(json_encode($log->getAttributes()))->not->toContain('TEST-token');
});

it('logs the buyer coming back and the API calls it triggers', function () {
    $payment = mpCheckout();
    Http::fake([MP_API.'/v1/payments/99' => Http::response([
        'id' => 99, 'status' => 'approved', 'external_reference' => $payment->uuid,
        'transaction_amount' => 15000, 'currency_id' => 'CLP',
        'payer' => ['email' => 'buyer@example.com', 'identification' => ['number' => '11111111-1']],
    ])]);

    $this->withCookie('laravel_session', 'secret-session')
        ->get(route('payments.commit', $payment).'?payment_id=99&status=approved');

    [$charge, $return, $complete] = $payment->logs()->get()->all();

    expect($return->type)->toBe(PaymentLog::TYPE_RETURN)
        ->and($return->direction)->toBe('in')
        ->and($return->request['query'])->toBe(['payment_id' => '99', 'status' => 'approved'])
        ->and($return->request['headers'])->not->toHaveKey('cookie')
        ->and($complete->type)->toBe(PaymentLog::TYPE_COMPLETE)
        ->and($complete->response['payer']['identification']['number'])->toBe('11111111-1');
});

it('logs failed API calls with the answer and the error', function () {
    Http::fake([MP_API.'/checkout/preferences' => Http::response(['message' => 'invalid unit_price'], 400)]);
    $payment = PaymentGateways::create('mercadopago', Money::CLP(15000));

    expect(fn () => PaymentGateways::charge($payment))->toThrow(GatewayCommunicationException::class);

    // An HTTP error keeps its status and body; `error` is for calls that got no answer.
    $log = $payment->logs()->sole();
    expect($log->status_code)->toBe(400)
        ->and($log->response)->toBe(['message' => 'invalid unit_price'])
        ->and($log->error)->toBeNull();
});

it('logs calls that got no answer with the error', function () {
    Http::fake(fn () => throw new ConnectionException('Connection timed out'));
    $payment = PaymentGateways::create('mercadopago', Money::CLP(15000));

    expect(fn () => PaymentGateways::charge($payment))->toThrow(GatewayCommunicationException::class);

    $log = $payment->logs()->sole();
    expect($log->status_code)->toBeNull()
        ->and($log->error)->toContain('Connection timed out');
});

it('logs refused notifications without a payment', function () {
    $this->withHeaders(['x-signature' => 'ts=1,v1=forged', 'x-request-id' => 'req-1'])
        ->postJson(route('payments.webhook', 'mercadopago').'?data.id=99&type=payment', ['type' => 'payment', 'data' => ['id' => '99']])
        ->assertUnauthorized();

    $log = PaymentLog::query()->sole();

    expect($log->payment_id)->toBeNull()
        ->and($log->type)->toBe(PaymentLog::TYPE_WEBHOOK)
        ->and($log->direction)->toBe('in')
        ->and($log->verified)->toBeFalse()
        ->and($log->request['headers']['x-signature'])->toBe('ts=1,v1=forged')
        ->and($log->request['body'])->toBe(['type' => 'payment', 'data' => ['id' => '99']]);
});

it('links a verified notification and its API calls to the payment', function () {
    config()->set('payment-gateways.gateways.mercadopago.webhook_secret', null);
    $payment = mpCheckout();
    Http::fake([MP_API.'/v1/payments/99' => Http::response([
        'id' => 99, 'status' => 'approved', 'external_reference' => $payment->uuid,
        'transaction_amount' => 15000, 'currency_id' => 'CLP',
    ])]);

    $this->postJson(route('payments.webhook', 'mercadopago').'?data.id=99&type=payment')->assertOk();

    $webhookLogs = $payment->logs()->where('type', PaymentLog::TYPE_WEBHOOK)->get();

    expect($webhookLogs)->toHaveCount(2)
        ->and($webhookLogs->firstWhere('direction', 'in')->verified)->toBeTrue()
        ->and($webhookLogs->firstWhere('direction', 'out')->response['status'])->toBe('approved');
});

it('masks secrets in bodies and urls', function () {
    Http::fake(['https://sandbox.flow.cl/api/payment/create' => Http::response(['url' => 'https://flow.test', 'token' => 'tok', 'flowOrder' => 1])]);
    $payment = PaymentGateways::create('flow', Money::CLP(1000), customer: new Customer(email: 'buyer@example.com'));

    PaymentGateways::charge($payment);

    $log = $payment->logs()->sole();
    expect($log->request['apiKey'])->toBe('[redacted]')
        ->and($log->request['s'])->toBe('[redacted]')
        ->and($log->request['email'])->toBe('buyer@example.com')
        ->and(json_encode($log->getAttributes()))->not->toContain('test-api-key');
});

it('does not log OAuth token calls', function () {
    Http::fake([
        'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'A21-secret', 'expires_in' => 3600]),
        'https://api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response(['id' => 'ORDER-1', 'links' => [['rel' => 'payer-action', 'href' => 'https://paypal.test']]], 201),
    ]);
    $payment = PaymentGateways::create('paypal', Money::USD(1000));

    PaymentGateways::charge($payment);

    expect($payment->logs()->pluck('url')->all())->toBe(['https://api-m.sandbox.paypal.com/v2/checkout/orders'])
        ->and(json_encode(PaymentLog::all()))->not->toContain('A21-secret');
});

it('can be turned off', function () {
    config()->set('payment-gateways.logs.enabled', false);

    mpCheckout();

    expect(PaymentLog::query()->count())->toBe(0);
});

it('never breaks a payment when a log cannot be written', function () {
    Schema::drop('payment_logs');

    $payment = mpCheckout();

    expect($payment->external_id)->toBe('pref-1');
});

it('prunes old logs only with a retention period', function () {
    $payment = mpCheckout();
    PaymentLog::query()->update(['created_at' => now()->subDays(100)]);

    $this->artisan('model:prune', ['--model' => [PaymentLog::class]]);
    expect(PaymentLog::query()->count())->toBe(1);

    config()->set('payment-gateways.logs.retention_days', 90);
    $this->artisan('model:prune', ['--model' => [PaymentLog::class]]);
    expect(PaymentLog::query()->count())->toBe(0)
        ->and($payment->fresh())->not->toBeNull();
});

it('clears the failure message once a rejected payment is paid', function () {
    $fake = PaymentGateways::fake('webpay')->completeWith(new ChargeResult(status: PaymentStatus::Failed, message: 'Declined'));
    $payment = PaymentGateways::create('webpay', Money::CLP(1000));
    PaymentGateways::charge($payment);
    PaymentGateways::complete($payment, new Request);

    expect($payment->failureMessage())->toBe('Declined');

    PaymentGateways::apply($payment, new ChargeResult(status: PaymentStatus::Paid));

    expect($payment->isPaid())->toBeTrue()
        ->and($payment->failureMessage())->toBeNull();
});

it('keeps application metadata apart from package data', function () {
    $payment = PaymentGateways::create(
        'webpay',
        Money::CLP(1000),
        customer: new Customer(email: 'a@b.cl'),
        metadata: ['voucher' => 'mine', 'refunds' => 'mine', 'message' => 'mine'],
    );
    PaymentGateways::fake('webpay')->completeWith(new ChargeResult(status: PaymentStatus::Failed, message: 'Declined'));
    PaymentGateways::charge($payment);
    PaymentGateways::complete($payment, new Request);

    expect($payment->fresh()->metadata)->toBe(['voucher' => 'mine', 'refunds' => 'mine', 'message' => 'mine'])
        ->and($payment->customer->email)->toBe('a@b.cl');
});
