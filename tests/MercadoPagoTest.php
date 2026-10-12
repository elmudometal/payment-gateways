<?php

use Arca\PaymentGateways\Events\PaymentApproved;
use Arca\PaymentGateways\Events\PaymentRejected;
use Arca\PaymentGateways\Models\Payment;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Schema::create('payments', function (Blueprint $table) {
        $table->id();
        $table->uuid('uuid')->nullable()->unique();
        $table->string('type');
        $table->string('token')->nullable();
        $table->string('authorization_code')->nullable();
        $table->string('status');
        $table->integer('amount');
        $table->text('comments')->nullable();
        $table->text('voucher')->nullable();
        $table->morphs('model');
        $table->softDeletes();
        $table->timestamps();
    });

    config()->set('payment-gateways.mercadopago.access_token', 'TEST-ACCESS-TOKEN');
    config()->set('payment-gateways.mercadopago.webhook_secret', '');
    config()->set('payment-gateways.mercadopago.currency', 'CLP');

    Event::fake([PaymentApproved::class, PaymentRejected::class]);

    $this->payment = Payment::factory()->create([
        'type' => 'mercadopago',
        'status' => Payment::PENDING_STATUS,
        'amount' => 15000,
        'comments' => 'Inscripción',
        'voucher' => null,
        'model_type' => Payment::class,
        'model_id' => 999,
    ]);
});

function mpPayment(Payment $payment, array $overrides = []): array
{
    return array_merge([
        'id' => 123456789,
        'status' => 'approved',
        'status_detail' => 'accredited',
        'external_reference' => $payment->uuid,
        'transaction_amount' => $payment->amount,
        'authorization_code' => 'AUTH01',
        'payment_method_id' => 'visa',
        'date_approved' => '2026-10-11T10:00:00.000-03:00',
    ], $overrides);
}

function mpSignature(string $dataId, string $requestId, string $ts, string $secret): string
{
    $hash = hash_hmac('sha256', "id:{$dataId};request-id:{$requestId};ts:{$ts};", $secret);

    return "ts={$ts},v1={$hash}";
}

// init

it('creates a preference and redirects to the checkout', function () {
    URL::forceRootUrl('https://tienda.example.cl');
    URL::forceScheme('https');
    Http::fake([
        'api.mercadopago.com/checkout/preferences' => Http::response([
            'id' => 'PREF-123',
            'init_point' => 'https://www.mercadopago.cl/checkout/v1/redirect?pref_id=PREF-123',
        ]),
    ]);

    $this->get(route('mercadopago.init', $this->payment->uuid))
        ->assertRedirect('https://www.mercadopago.cl/checkout/v1/redirect?pref_id=PREF-123');

    expect($this->payment->fresh()->token)->toBe('PREF-123');

    Http::assertSent(function (HttpRequest $request) {
        return $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer TEST-ACCESS-TOKEN')
            && $request['external_reference'] === $this->payment->uuid
            && $request['items'][0]['unit_price'] == 15000
            && $request['items'][0]['currency_id'] === 'CLP'
            && $request['items'][0]['title'] === 'Inscripción'
            && $request['back_urls']['success'] === route('mercadopago.commit', $this->payment->uuid)
            && $request['notification_url'] === route('mercadopago.notification', $this->payment->uuid)
            && $request['auto_return'] === 'approved'
            && ! isset($request['statement_descriptor']);
    });
});

it('omits auto_return and the webhook url when the site is not publicly reachable', function (string $root) {
    URL::forceRootUrl($root);
    URL::forceScheme((string) parse_url($root, PHP_URL_SCHEME));
    Http::fake([
        'api.mercadopago.com/checkout/preferences' => Http::response(['id' => 'PREF-123', 'init_point' => 'https://www.mercadopago.cl/checkout']),
    ]);

    $this->get(route('mercadopago.init', $this->payment->uuid))->assertRedirect('https://www.mercadopago.cl/checkout');

    Http::assertSent(fn (HttpRequest $request) => ! isset($request['auto_return'])
        && ! isset($request['notification_url'])
        && $request['back_urls']['success'] === route('mercadopago.commit', $this->payment->uuid));
})->with([
    'localhost' => ['http://localhost'],
    'plain http' => ['http://tienda.example.cl'],
    'herd .test' => ['https://tienda.test'],
    'loopback' => ['https://127.0.0.1:8000'],
]);

it('redirects to rejected when the preference cannot be created', function () {
    Http::fake(['api.mercadopago.com/*' => Http::response(['message' => 'invalid token'], 401)]);

    $this->get(route('mercadopago.init', $this->payment->uuid))
        ->assertRedirect(route('mercadopago.rejected', $this->payment->uuid));
});

it('does not create a preference for an already paid payment', function () {
    Http::fake();
    $this->payment->update(['status' => Payment::PAID_STATUS]);

    $this->get(route('mercadopago.init', $this->payment->uuid))
        ->assertRedirect(route('mercadopago.successful', $this->payment->uuid));

    Http::assertNothingSent();
});

// commit (back_urls)

it('marks the payment as paid on an approved return', function () {
    Http::fake(['api.mercadopago.com/v1/payments/123456789' => Http::response(mpPayment($this->payment))]);

    $this->get(route('mercadopago.commit', $this->payment->uuid).'?payment_id=123456789&status=approved')
        ->assertRedirect(route('mercadopago.successful', $this->payment->uuid));

    $payment = $this->payment->fresh();
    expect($payment->status)->toBe(Payment::PAID_STATUS)
        ->and($payment->authorization_code)->toBe('AUTH01')
        ->and($payment->voucher['id'])->toBe(123456789);

    Event::assertDispatched(PaymentApproved::class);
});

it('ignores the status sent in the query string', function () {
    Http::fake(['api.mercadopago.com/v1/payments/*' => Http::response(mpPayment($this->payment, [
        'status' => 'rejected',
        'status_detail' => 'cc_rejected_insufficient_amount',
    ]))]);

    $this->get(route('mercadopago.commit', $this->payment->uuid).'?payment_id=123456789&status=approved')
        ->assertRedirect(route('mercadopago.rejected', $this->payment->uuid));

    expect($this->payment->fresh()->status)->toBe(Payment::CANCELED_STATUS);
    Event::assertDispatched(PaymentRejected::class);

    $this->get(route('mercadopago.rejected', $this->payment->uuid))
        ->assertOk()
        ->assertSee('Tu tarjeta no tiene fondos suficientes');
});

it('redirects to pending for in process payments', function () {
    Http::fake(['api.mercadopago.com/v1/payments/*' => Http::response(mpPayment($this->payment, [
        'status' => 'in_process',
        'status_detail' => 'pending_contingency',
    ]))]);

    $this->get(route('mercadopago.commit', $this->payment->uuid).'?payment_id=123456789')
        ->assertRedirect(route('mercadopago.pending', $this->payment->uuid));

    expect($this->payment->fresh()->status)->toBe(Payment::PENDING_STATUS);

    $this->get(route('mercadopago.pending', $this->payment->uuid))->assertOk()->assertSee('PAGO PENDIENTE');
});

it('rejects a payment belonging to another external reference', function () {
    Http::fake(['api.mercadopago.com/v1/payments/*' => Http::response(mpPayment($this->payment, [
        'external_reference' => 'other-uuid',
    ]))]);

    $this->get(route('mercadopago.commit', $this->payment->uuid).'?payment_id=123456789')
        ->assertRedirect(route('mercadopago.rejected', $this->payment->uuid));

    expect($this->payment->fresh()->status)->toBe(Payment::PENDING_STATUS);
    Event::assertNotDispatched(PaymentApproved::class);
});

it('does not approve a payment with a lower amount', function () {
    Http::fake(['api.mercadopago.com/v1/payments/*' => Http::response(mpPayment($this->payment, [
        'transaction_amount' => 10,
    ]))]);

    $this->get(route('mercadopago.commit', $this->payment->uuid).'?payment_id=123456789')
        ->assertRedirect(route('mercadopago.rejected', $this->payment->uuid));

    expect($this->payment->fresh()->status)->toBe(Payment::PENDING_STATUS);
});

it('redirects to rejected when the buyer returns without paying', function () {
    Http::fake();

    $this->get(route('mercadopago.commit', $this->payment->uuid).'?payment_id=null&status=null')
        ->assertRedirect(route('mercadopago.rejected', $this->payment->uuid));

    Http::assertNothingSent();

    $this->get(route('mercadopago.rejected', $this->payment->uuid))->assertOk()->assertSee('Error desconocido');
});

it('shows the successful page only for paid payments', function () {
    $this->get(route('mercadopago.successful', $this->payment->uuid))
        ->assertRedirect(route('mercadopago.rejected', $this->payment->uuid));

    $this->payment->update(['status' => Payment::PAID_STATUS, 'voucher' => mpPayment($this->payment)]);

    $this->get(route('mercadopago.successful', $this->payment->uuid))
        ->assertOk()
        ->assertSee('PAGO REALIZADO CON ÉXITO', false);
});

// notification (webhook)

it('updates the payment from a webhook notification', function () {
    Http::fake(['api.mercadopago.com/v1/payments/123456789' => Http::response(mpPayment($this->payment))]);

    $this->postJson(
        route('mercadopago.notification', $this->payment->uuid).'?data.id=123456789&type=payment',
        ['type' => 'payment', 'action' => 'payment.created', 'data' => ['id' => '123456789']]
    )->assertOk();

    expect($this->payment->fresh()->status)->toBe(Payment::PAID_STATUS);
    Event::assertDispatched(PaymentApproved::class);
});

it('accepts the data id from the json body', function () {
    Http::fake(['api.mercadopago.com/v1/payments/123456789' => Http::response(mpPayment($this->payment))]);

    $this->postJson(route('mercadopago.notification', $this->payment->uuid), [
        'type' => 'payment',
        'data' => ['id' => '123456789'],
    ])->assertOk();

    expect($this->payment->fresh()->status)->toBe(Payment::PAID_STATUS);
});

it('accepts legacy ipn notifications', function () {
    Http::fake(['api.mercadopago.com/v1/payments/123456789' => Http::response(mpPayment($this->payment))]);

    $this->post(route('mercadopago.notification', $this->payment->uuid).'?topic=payment&id=123456789')
        ->assertOk();

    expect($this->payment->fresh()->status)->toBe(Payment::PAID_STATUS);
});

it('ignores notifications that are not payments', function () {
    Http::fake();

    $this->postJson(route('mercadopago.notification', $this->payment->uuid).'?topic=merchant_order&id=1')
        ->assertOk()
        ->assertJson(['message' => 'ignored']);

    Http::assertNothingSent();
});

it('validates the webhook signature when a secret is configured', function () {
    config()->set('payment-gateways.mercadopago.webhook_secret', 'SECRET');
    Http::fake(['api.mercadopago.com/v1/payments/123456789' => Http::response(mpPayment($this->payment))]);

    $url = route('mercadopago.notification', $this->payment->uuid).'?data.id=123456789&type=payment';

    $this->postJson($url, [], [
        'x-signature' => mpSignature('123456789', 'req-1', '1700000000', 'WRONG'),
        'x-request-id' => 'req-1',
    ])->assertStatus(401);

    $this->postJson($url, [], ['x-request-id' => 'req-1'])->assertStatus(401);

    Http::assertNothingSent();
    expect($this->payment->fresh()->status)->toBe(Payment::PENDING_STATUS);

    $this->postJson($url, [], [
        'x-signature' => mpSignature('123456789', 'req-1', '1700000000', 'SECRET'),
        'x-request-id' => 'req-1',
    ])->assertOk();

    expect($this->payment->fresh()->status)->toBe(Payment::PAID_STATUS);
});

it('does not downgrade a paid payment on out of order notifications', function () {
    $this->payment->update(['status' => Payment::PAID_STATUS, 'voucher' => mpPayment($this->payment)]);
    Http::fake(['api.mercadopago.com/v1/payments/*' => Http::response(mpPayment($this->payment, ['status' => 'pending']))]);

    $this->postJson(route('mercadopago.notification', $this->payment->uuid), [
        'type' => 'payment',
        'data' => ['id' => '123456789'],
    ])->assertOk();

    expect($this->payment->fresh()->status)->toBe(Payment::PAID_STATUS);
});

it('marks a paid payment as reversed when refunded', function () {
    $this->payment->update(['status' => Payment::PAID_STATUS, 'voucher' => mpPayment($this->payment)]);
    Http::fake(['api.mercadopago.com/v1/payments/*' => Http::response(mpPayment($this->payment, ['status' => 'refunded']))]);

    $this->postJson(route('mercadopago.notification', $this->payment->uuid), [
        'type' => 'payment',
        'data' => ['id' => '123456789'],
    ])->assertOk();

    expect($this->payment->fresh()->status)->toBe(Payment::REVERSED_STATUS);
});

it('returns 500 so mercado pago retries when the api fails', function () {
    Http::fake(['api.mercadopago.com/*' => Http::response([], 500)]);

    $this->postJson(route('mercadopago.notification', $this->payment->uuid), [
        'type' => 'payment',
        'data' => ['id' => '123456789'],
    ])->assertStatus(500);
});

// routes

it('serves the buyer pages through the web middleware so hooks can use the session', function (string $route) {
    expect(Route::getRoutes()->getByName($route)->gatherMiddleware())->toContain('web');
})->with(['mercadopago.init', 'mercadopago.commit', 'mercadopago.successful', 'mercadopago.pending', 'mercadopago.rejected']);

it('keeps the webhook outside the web middleware so it is not blocked by csrf', function () {
    expect(Route::getRoutes()->getByName('mercadopago.notification')->gatherMiddleware())->not->toContain('web');
});

// webhook hardening

it('refuses unsigned notifications in production when no secret is configured', function () {
    app()->detectEnvironment(fn () => 'production');
    Http::fake();

    $this->postJson(route('mercadopago.notification', $this->payment->uuid).'?data.id=123456789&type=payment')
        ->assertStatus(401);

    Http::assertNothingSent();
    expect($this->payment->fresh()->status)->toBe(Payment::PENDING_STATUS);
});

it('refuses a correctly signed notification older than the configured tolerance', function () {
    config()->set('payment-gateways.mercadopago.webhook_secret', 'SECRET');
    config()->set('payment-gateways.mercadopago.webhook_tolerance', 300);
    Http::fake(['api.mercadopago.com/v1/payments/123456789' => Http::response(mpPayment($this->payment))]);
    $url = route('mercadopago.notification', $this->payment->uuid).'?data.id=123456789&type=payment';

    $this->postJson($url, [], [
        'x-signature' => mpSignature('123456789', 'req-1', (string) (time() - 3600), 'SECRET'),
        'x-request-id' => 'req-1',
    ])->assertStatus(401);

    expect($this->payment->fresh()->status)->toBe(Payment::PENDING_STATUS);

    $this->postJson($url, [], [
        'x-signature' => mpSignature('123456789', 'req-1', (string) time(), 'SECRET'),
        'x-request-id' => 'req-1',
    ])->assertOk();

    expect($this->payment->fresh()->status)->toBe(Payment::PAID_STATUS);
});

// api resilience

it('retries reading a payment when the api has a temporary failure', function () {
    Sleep::fake();
    Http::fakeSequence('api.mercadopago.com/v1/payments/*')
        ->push([], 503)
        ->push(mpPayment($this->payment));

    $this->postJson(route('mercadopago.notification', $this->payment->uuid), [
        'type' => 'payment',
        'data' => ['id' => '123456789'],
    ])->assertOk();

    expect($this->payment->fresh()->status)->toBe(Payment::PAID_STATUS);
    Http::assertSentCount(2);
});

it('does not retry a client error from the api', function () {
    Sleep::fake();
    Http::fake(['api.mercadopago.com/v1/payments/*' => Http::response(['message' => 'not found'], 404)]);

    $this->postJson(route('mercadopago.notification', $this->payment->uuid), [
        'type' => 'payment',
        'data' => ['id' => '123456789'],
    ])->assertStatus(500);

    Http::assertSentCount(1);
});

it('never retries creating a preference', function () {
    Sleep::fake();
    Http::fake(['api.mercadopago.com/checkout/preferences' => Http::response([], 503)]);

    $this->get(route('mercadopago.init', $this->payment->uuid))
        ->assertRedirect(route('mercadopago.rejected', $this->payment->uuid));

    Http::assertSentCount(1);
});
