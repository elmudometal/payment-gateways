<?php

use Arca\PaymentGateways\Drivers\Flow\FlowDriver;
use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\Exceptions\GatewayCommunicationException;
use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\ValueObjects\ChargeIntent;
use Arca\PaymentGateways\ValueObjects\Customer;
use Arca\PaymentGateways\ValueObjects\Money;
use Arca\PaymentGateways\ValueObjects\ReturnUrls;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->driver = new FlowDriver([
        'environment' => 'sandbox',
        'api_key' => 'test-api-key',
        'secret_key' => 'test-secret',
    ]);
});

it('has correct name', function () {
    expect($this->driver->name())->toBe('flow');
});

it('charges and returns redirect', function () {
    Http::fake([
        'sandbox.flow.cl/api/payment/create' => Http::response([
            'url' => 'https://sandbox.flow.cl/app/web/pay.php',
            'token' => 'flow-token-123',
            'flowOrder' => 12345,
        ]),
    ]);

    $payment = Payment::factory()->gateway('flow')->create();
    $intent = new ChargeIntent(
        money: Money::CLP(30000),
        description: 'Test Flow',
        urls: new ReturnUrls(
            returnUrl: 'https://example.com/return',
            webhookUrl: 'https://example.com/webhook',
        ),
        customer: new Customer(email: 'test@example.com'),
    );

    $result = $this->driver->charge($payment, $intent);

    expect($result->status)->toBe(PaymentStatus::Pending)
        ->and($result->externalId)->toBe('flow-token-123')
        ->and($result->requiresRedirect())->toBeTrue()
        ->and($result->redirectUrl)->toContain('flow-token-123');

    expect($payment->fresh()->external_id)->toBe('flow-token-123');
});

it('queries a paid payment', function () {
    Http::fake([
        'sandbox.flow.cl/api/payment/getStatus*' => Http::response([
            'status' => 2,
            'authorizationCode' => 'AUTH-456',
        ]),
    ]);

    $payment = Payment::factory()->gateway('flow')->create([
        'external_id' => 'flow-token-123',
    ]);

    $result = $this->driver->query($payment);

    expect($result->status)->toBe(PaymentStatus::Paid)
        ->and($result->authorizationCode)->toBe('AUTH-456');
});

it('queries a rejected payment', function () {
    Http::fake([
        'sandbox.flow.cl/api/payment/getStatus*' => Http::response([
            'status' => 3,
        ]),
    ]);

    $payment = Payment::factory()->gateway('flow')->create([
        'external_id' => 'flow-token-123',
    ]);

    $result = $this->driver->query($payment);

    expect($result->status)->toBe(PaymentStatus::Failed);
});

it('throws on API error during charge', function () {
    Http::fake([
        'sandbox.flow.cl/api/payment/create' => Http::response('Server Error', 500),
    ]);

    $payment = Payment::factory()->gateway('flow')->create();
    $intent = new ChargeIntent(
        money: Money::CLP(1000),
        description: 'Test',
        urls: new ReturnUrls(returnUrl: 'https://example.com'),
        customer: new Customer(email: 'test@example.com'),
    );

    $this->driver->charge($payment, $intent);
})->throws(GatewayCommunicationException::class);
