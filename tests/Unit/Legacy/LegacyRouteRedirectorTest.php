<?php

use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\Support\Legacy\LegacyRouteRedirector;

beforeEach(function () {
    LegacyRouteRedirector::register();
});

it('redirects legacy init route to payments.show', function () {
    $payment = Payment::factory()->create();

    $this->get("webpay/init/{$payment->uuid}")
        ->assertRedirect(route('payments.show', $payment))
        ->assertStatus(301);
});

it('redirects legacy successful route to payments.success', function () {
    $payment = Payment::factory()->create();

    $this->get("webpay/successful/{$payment->uuid}")
        ->assertRedirect(route('payments.success', $payment))
        ->assertStatus(301);
});

it('redirects legacy rejected route to payments.failed', function () {
    $payment = Payment::factory()->create();

    $this->get("webpay/rejected/{$payment->uuid}")
        ->assertRedirect(route('payments.failed', $payment))
        ->assertStatus(301);
});

it('registers routes for all v1 gateways', function () {
    $gateways = ['webpay', 'getnet', 'paypal', 'flow', 'niubiz'];

    foreach ($gateways as $gw) {
        expect(route("{$gw}.init", ['payment' => 'test-uuid']))->toBeString()
            ->and(route("{$gw}.commit", ['payment' => 'test-uuid']))->toBeString()
            ->and(route("{$gw}.successful", ['payment' => 'test-uuid']))->toBeString()
            ->and(route("{$gw}.rejected", ['payment' => 'test-uuid']))->toBeString();
    }
});
