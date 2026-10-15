<?php

use Arca\PaymentGateways\Events\WebhookReceived;
use Arca\PaymentGateways\Support\PaymentManager;
use Illuminate\Support\Facades\Event;

it('dispatches WebhookReceived event for valid webhook', function () {
    Event::fake();

    $manager = app(PaymentManager::class);
    $manager->fake('flow');

    $this->post(route('payments.webhook', 'flow'), ['token' => 'test-token'])
        ->assertOk();

    Event::assertDispatched(WebhookReceived::class, function ($event) {
        return $event->gateway === 'flow';
    });
});

it('returns 404 for gateway without webhook support', function () {
    $manager = app(PaymentManager::class);

    $manager->extend('no-webhook', fn () => new class implements \Arca\PaymentGateways\Contracts\PaymentGateway
    {
        public function name(): string
        {
            return 'no-webhook';
        }

        public function charge($p, $i): \Arca\PaymentGateways\ValueObjects\ChargeResult
        {
            return new \Arca\PaymentGateways\ValueObjects\ChargeResult(
                status: \Arca\PaymentGateways\Enums\PaymentStatus::Paid,
            );
        }

        public function query($p): \Arca\PaymentGateways\ValueObjects\ChargeResult
        {
            return new \Arca\PaymentGateways\ValueObjects\ChargeResult(
                status: \Arca\PaymentGateways\Enums\PaymentStatus::Pending,
            );
        }
    });

    $this->post(route('payments.webhook', 'no-webhook'))
        ->assertNotFound();
});
