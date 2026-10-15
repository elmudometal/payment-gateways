<?php

use Arca\PaymentGateways\Events\WebhookReceived;
use Arca\PaymentGateways\Exceptions\GatewayCommunicationException;
use Arca\PaymentGateways\Facades\PaymentGateways;
use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\Support\Testing\FakeDriver;
use Arca\PaymentGateways\ValueObjects\Money;
use Arca\PaymentGateways\ValueObjects\WebhookResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

it('updates the payment from a verified notification', function () {
    Event::fake([WebhookReceived::class]);
    PaymentGateways::fake('flow');
    $payment = PaymentGateways::create('flow', Money::CLP(1000));

    $this->postJson(route('payments.webhook', 'flow'), ['payment' => $payment->uuid])
        ->assertOk()
        ->assertJson(['message' => 'ok']);

    expect($payment->fresh()->isPaid())->toBeTrue();
    Event::assertDispatched(WebhookReceived::class, fn ($e) => $e->gateway === 'flow');
});

it('answers 2xx for notifications about unknown payments', function () {
    PaymentGateways::fake('flow');

    $this->postJson(route('payments.webhook', 'flow'), ['payment' => 'nope'])
        ->assertOk()
        ->assertJson(['message' => 'ignored']);
});

it('refuses notifications with an invalid signature', function () {
    PaymentGateways::fake('flow')->rejectWebhooks();

    $this->postJson(route('payments.webhook', 'flow'))->assertUnauthorized();
});

it('answers 404 for unknown gateways and gateways without webhooks', function () {
    $this->postJson(route('payments.webhook', 'unknown'))->assertNotFound();
    $this->postJson(route('payments.webhook', 'niubiz'))->assertNotFound();
});

it('answers 500 when the gateway API is down, so the gateway retries', function () {
    PaymentGateways::extend('flow', fn () => new class('flow') extends FakeDriver
    {
        public function handleWebhook(Request $request): ?WebhookResult
        {
            throw new GatewayCommunicationException('down', 'flow');
        }
    });

    $this->postJson(route('payments.webhook', 'flow'))->assertStatus(500);
});

it('does not need a csrf token', function () {
    PaymentGateways::fake('flow');

    $this->post(route('payments.webhook', 'flow'))->assertOk();
});

it('answers 500 when the gateway credentials are missing, so it retries later', function () {
    config()->set('payment-gateways.gateways.flow.api_key', null);
    Payment::factory()->gateway('flow')->create(['external_id' => 'flow-token']);

    $this->postJson(route('payments.webhook', 'flow'), ['token' => 'flow-token'])->assertStatus(500);
});

it('refuses Getnet notifications when no tran key is configured', function () {
    config()->set('payment-gateways.gateways.getnet.tran_key', null);

    $this->postJson(route('payments.webhook', 'getnet'), ['requestId' => 1, 'status' => ['status' => 'APPROVED'], 'signature' => sha1('1APPROVED')])
        ->assertUnauthorized();
});
