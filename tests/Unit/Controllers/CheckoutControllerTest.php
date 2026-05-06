<?php

use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\Support\PaymentManager;

it('shows checkout page for pending payment', function () {
    $payment = Payment::factory()->create();

    $this->get(route('payments.show', $payment))
        ->assertOk()
        ->assertViewIs('payment-gateways::checkout');
});

it('redirects paid payment to success page', function () {
    $payment = Payment::factory()->paid()->create();

    $this->get(route('payments.show', $payment))
        ->assertRedirect(route('payments.success', $payment));
});

it('redirects failed payment to failed page', function () {
    $payment = Payment::factory()->failed()->create();

    $this->get(route('payments.show', $payment))
        ->assertRedirect(route('payments.failed', $payment));
});

it('shows success page', function () {
    $payment = Payment::factory()->paid()->create();

    $this->get(route('payments.success', $payment))
        ->assertOk()
        ->assertViewIs('payment-gateways::success');
});

it('shows failed page', function () {
    $payment = Payment::factory()->failed()->create();

    $this->get(route('payments.failed', $payment))
        ->assertOk()
        ->assertViewIs('payment-gateways::failed');
});

it('commits payment via default query when no driver-specific params', function () {
    $manager = app(PaymentManager::class);
    $fake = $manager->fake('webpay');

    $payment = Payment::factory()->gateway('webpay')->create();

    $this->post(route('payments.commit', $payment))
        ->assertRedirect();
});
