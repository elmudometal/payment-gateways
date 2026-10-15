<?php

use Arca\PaymentGateways\Drivers\Niubiz\NiubizDriver;
use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\Exceptions\GatewayCommunicationException;
use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\ValueObjects\ChargeIntent;
use Arca\PaymentGateways\ValueObjects\Money;
use Arca\PaymentGateways\ValueObjects\ReturnUrls;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->driver = new NiubizDriver([
        'environment' => 'sandbox',
        'merchant_id' => 'test-merchant',
        'user' => 'test-user',
        'password' => 'test-pass',
        'currency' => 'PEN',
    ]);
});

it('has correct name', function () {
    expect($this->driver->name())->toBe('niubiz');
});

it('charges and returns session token', function () {
    Http::fake([
        'apisandbox.vnforappstest.com/api.security/v1/security' => Http::response('access-token-123'),
        'apisandbox.vnforappstest.com/api.ecommerce/v2/ecommerce/token/session/*' => Http::response([
            'sessionKey' => 'session-token-456',
        ]),
    ]);

    $payment = Payment::factory()->gateway('niubiz')->create();
    $intent = new ChargeIntent(
        money: Money::PEN(5000),
        description: 'Test Niubiz',
        urls: new ReturnUrls(returnUrl: 'https://example.com/return'),
    );

    $result = $this->driver->charge($payment, $intent);

    expect($result->status)->toBe(PaymentStatus::Pending)
        ->and($result->externalId)->toBe('session-token-456')
        ->and($result->raw)->toHaveKey('sessionToken')
        ->and($result->raw)->toHaveKey('merchantId')
        ->and($result->raw)->toHaveKey('jsUrl');

    expect($payment->fresh()->external_id)->toBe('session-token-456');
});

it('authorizes a successful transaction', function () {
    Http::fake([
        'apisandbox.vnforappstest.com/api.security/v1/security' => Http::response('access-token-123'),
        'apisandbox.vnforappstest.com/api.authorization/v3/authorization/ecommerce/*' => Http::response([
            'dataMap' => [
                'ACTION_CODE' => '000',
                'AUTHORIZATION_CODE' => 'AUTH-789',
            ],
            'order' => [
                'actionCode' => '000',
                'authorizationCode' => 'AUTH-789',
            ],
        ]),
    ]);

    $payment = Payment::factory()->gateway('niubiz')->create([
        'external_id' => 'session-token-456',
        'amount' => 5000,
    ]);

    $result = $this->driver->authorize('txn-token-xyz', $payment);

    expect($result->status)->toBe(PaymentStatus::Paid)
        ->and($result->authorizationCode)->toBe('AUTH-789');
});

it('handles declined authorization', function () {
    Http::fake([
        'apisandbox.vnforappstest.com/api.security/v1/security' => Http::response('access-token-123'),
        'apisandbox.vnforappstest.com/api.authorization/v3/authorization/ecommerce/*' => Http::response([
            'dataMap' => [
                'ACTION_CODE' => '051',
            ],
            'order' => [
                'actionCode' => '051',
            ],
        ]),
    ]);

    $payment = Payment::factory()->gateway('niubiz')->create([
        'external_id' => 'session-token-456',
        'amount' => 5000,
    ]);

    $result = $this->driver->authorize('txn-token-xyz', $payment);

    expect($result->status)->toBe(PaymentStatus::Failed)
        ->and($result->message)->toBe('Fondos insuficientes');
});

it('throws on security API failure', function () {
    Http::fake([
        'apisandbox.vnforappstest.com/api.security/v1/security' => Http::response('Unauthorized', 401),
    ]);

    $payment = Payment::factory()->gateway('niubiz')->create();
    $intent = new ChargeIntent(
        money: Money::PEN(1000),
        description: 'Test',
        urls: new ReturnUrls(returnUrl: 'https://example.com'),
    );

    $this->driver->charge($payment, $intent);
})->throws(GatewayCommunicationException::class);
