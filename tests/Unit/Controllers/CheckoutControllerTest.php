<?php

use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\Exceptions\GatewayCommunicationException;
use Arca\PaymentGateways\Facades\PaymentGateways;
use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\Support\Testing\FakeDriver;
use Arca\PaymentGateways\ValueObjects\ChargeIntent;
use Arca\PaymentGateways\ValueObjects\ChargeResult;
use Arca\PaymentGateways\ValueObjects\Money;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->fake = PaymentGateways::fake('webpay');
    $this->payment = PaymentGateways::create('webpay', Money::CLP(15000), 'Order #1');
});

it('redirects the buyer to the gateway', function () {
    $this->get($this->payment->checkoutUrl())
        ->assertRedirect('https://checkout.fake/'.$this->payment->uuid);

    expect($this->payment->fresh()->external_id)->toBe('fake-'.$this->payment->uuid);
});

it('renders embedded checkouts', function () {
    $this->fake->respondWith(new ChargeResult(
        status: PaymentStatus::Pending,
        view: 'payment-gateways::success',
    ));

    $this->get($this->payment->checkoutUrl())
        ->assertOk()
        ->assertSee('15.000 CLP');
});

it('shows an error page when the gateway is down, keeping the payment pending', function () {
    PaymentGateways::extend('webpay', fn () => new class('webpay') extends FakeDriver
    {
        public function charge(Payment $payment, ChargeIntent $intent): ChargeResult
        {
            throw new GatewayCommunicationException('timeout', 'webpay');
        }
    });

    $this->get($this->payment->checkoutUrl())
        ->assertStatus(502)
        ->assertSee(__('payment-gateways::messages.gateway_error'));

    expect($this->payment->fresh()->isPending())->toBeTrue();
});

it('does not start the checkout again for a settled payment', function () {
    $this->payment->update(['status' => PaymentStatus::Paid]);

    $this->get($this->payment->checkoutUrl())->assertRedirect(route('payments.success', $this->payment));
    $this->fake->assertNotCharged();
});

it('accepts the gateway posting the buyer back without a csrf token', function () {
    $this->post(route('payments.commit', $this->payment), ['token' => 'abc'])
        ->assertRedirect(route('payments.success', $this->payment));

    expect($this->payment->fresh()->isPaid())->toBeTrue();
});

it('sends the buyer to the page that matches the status', function (PaymentStatus $status, string $page) {
    $this->fake->completeWith(new ChargeResult(status: $status));

    $this->get(route('payments.commit', $this->payment))
        ->assertRedirect(route("payments.{$page}", $this->payment));
})->with([
    [PaymentStatus::Paid, 'success'],
    [PaymentStatus::Authorized, 'success'],
    [PaymentStatus::Pending, 'pending'],
    [PaymentStatus::Failed, 'failed'],
]);

it('renders the result pages', function () {
    $this->payment->update(['status' => PaymentStatus::Paid, 'authorization_code' => 'AUTH-1']);
    $this->get(route('payments.success', $this->payment))->assertOk()->assertSee('AUTH-1');

    $failed = PaymentGateways::create('webpay', Money::CLP(1000));
    $failed->update(['status' => PaymentStatus::Failed, 'failure_message' => 'Fondos insuficientes']);
    $this->get(route('payments.failed', $failed))->assertOk()->assertSee('Fondos insuficientes');

    $pending = PaymentGateways::create('webpay', Money::CLP(1000));
    $this->get(route('payments.pending', $pending))->assertOk()->assertSee(route('payments.commit', $pending));
});

it('keeps every result page consistent with the status', function () {
    $this->get(route('payments.success', $this->payment))->assertRedirect(route('payments.pending', $this->payment));
    $this->get(route('payments.failed', $this->payment))->assertRedirect(route('payments.pending', $this->payment));
});

it('redirects to the application pages when configured', function () {
    Route::get('order/{payment}/thanks', fn () => 'thanks')->name('shop.thanks');
    config()->set('payment-gateways.redirects.success', 'shop.thanks');

    $this->get(route('payments.commit', $this->payment))
        ->assertRedirect(route('shop.thanks', $this->payment));
});

it('answers 404 for unknown payments', function () {
    $this->get('/payments/not-a-uuid')->assertNotFound();
    $this->get('/payments/'.Str::uuid())->assertNotFound();
});
