<?php

use Arca\PaymentGateways\Drivers\Paypal\PaypalDriver;
use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\Exceptions\GatewayCommunicationException;
use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\ValueObjects\ChargeIntent;
use Arca\PaymentGateways\ValueObjects\Money;
use Arca\PaymentGateways\ValueObjects\ReturnUrls;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->driver = new PaypalDriver([
        'environment' => 'sandbox',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'currency' => 'USD',
    ]);
});

it('has correct name', function () {
    expect($this->driver->name())->toBe('paypal');
});

it('creates an order with redirect', function () {
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'test-access-token',
        ]),
        'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
            'id' => 'ORDER-123',
            'status' => 'CREATED',
            'links' => [
                ['rel' => 'self', 'href' => 'https://api.paypal.com/v2/checkout/orders/ORDER-123'],
                ['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-123'],
            ],
        ]),
    ]);

    $payment = Payment::factory()->gateway('paypal')->create();
    $intent = new ChargeIntent(
        money: Money::USD(1999),
        description: 'PayPal test',
        urls: new ReturnUrls(
            returnUrl: 'https://example.com/return',
            cancelUrl: 'https://example.com/cancel',
        ),
    );

    $result = $this->driver->charge($payment, $intent);

    expect($result->status)->toBe(PaymentStatus::Pending)
        ->and($result->externalId)->toBe('ORDER-123')
        ->and($result->requiresRedirect())->toBeTrue()
        ->and($result->redirectUrl)->toContain('sandbox.paypal.com');

    expect($payment->fresh()->external_id)->toBe('ORDER-123');
});

it('captures a payment successfully', function () {
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'test-access-token',
        ]),
        'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-123/capture' => Http::response([
            'id' => 'ORDER-123',
            'status' => 'COMPLETED',
            'purchase_units' => [
                [
                    'payments' => [
                        'captures' => [
                            ['id' => 'CAPTURE-456'],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $payment = Payment::factory()->gateway('paypal')->create([
        'external_id' => 'ORDER-123',
    ]);

    $result = $this->driver->capture($payment);

    expect($result->status)->toBe(PaymentStatus::Paid)
        ->and($result->authorizationCode)->toBe('CAPTURE-456');
});

it('handles failed capture', function () {
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'test-access-token',
        ]),
        'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-123/capture' => Http::response([
            'status' => 'FAILED',
            'details' => [
                ['description' => 'Instrument declined'],
            ],
        ]),
    ]);

    $payment = Payment::factory()->gateway('paypal')->create([
        'external_id' => 'ORDER-123',
    ]);

    $result = $this->driver->capture($payment);

    expect($result->status)->toBe(PaymentStatus::Failed);
});

it('refunds a payment', function () {
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'test-access-token',
        ]),
        'api-m.sandbox.paypal.com/v2/payments/captures/CAPTURE-456/refund' => Http::response([
            'id' => 'REFUND-789',
            'status' => 'COMPLETED',
        ]),
    ]);

    $payment = Payment::factory()->gateway('paypal')->paid()->create([
        'external_id' => 'ORDER-123',
        'authorization_code' => 'CAPTURE-456',
    ]);

    $result = $this->driver->refund($payment);

    expect($result->isRefunded())->toBeTrue()
        ->and($result->refundId)->toBe('REFUND-789');
});

it('throws on auth failure', function () {
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response('Unauthorized', 401),
    ]);

    $payment = Payment::factory()->gateway('paypal')->create();
    $intent = new ChargeIntent(
        money: Money::USD(1000),
        description: 'Test',
        urls: new ReturnUrls(returnUrl: 'https://example.com'),
    );

    $this->driver->charge($payment, $intent);
})->throws(GatewayCommunicationException::class);
