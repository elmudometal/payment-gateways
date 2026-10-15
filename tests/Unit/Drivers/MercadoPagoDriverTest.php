<?php

use Arca\PaymentGateways\Drivers\MercadoPago\MercadoPagoDriver;
use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\Exceptions\GatewayCommunicationException;
use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\ValueObjects\ChargeIntent;
use Arca\PaymentGateways\ValueObjects\Customer;
use Arca\PaymentGateways\ValueObjects\Money;
use Arca\PaymentGateways\ValueObjects\ReturnUrls;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->driver = new MercadoPagoDriver([
        'environment' => 'sandbox',
        'access_token' => 'TEST-fake-access-token',
    ]);
});

it('has correct name', function () {
    expect($this->driver->name())->toBe('mercadopago');
});

it('creates a preference and returns redirect to sandbox', function () {
    Http::fake([
        'api.mercadopago.com/checkout/preferences' => Http::response([
            'id' => 'PREF-123456',
            'init_point' => 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=PREF-123456',
            'sandbox_init_point' => 'https://sandbox.mercadopago.com.ar/checkout/v1/redirect?pref_id=PREF-123456',
        ]),
    ]);

    $payment = Payment::factory()->gateway('mercadopago')->create();
    $intent = new ChargeIntent(
        money: Money::fromDecimal(15000, \Arca\PaymentGateways\Enums\Currency::CLP),
        description: 'Compra de prueba',
        urls: new ReturnUrls(
            returnUrl: 'https://example.com/return',
            cancelUrl: 'https://example.com/cancel',
            webhookUrl: 'https://example.com/webhook',
        ),
        customer: new Customer(
            email: 'comprador@example.com',
            name: 'Juan Pérez',
        ),
    );

    $result = $this->driver->charge($payment, $intent);

    expect($result->status)->toBe(PaymentStatus::Pending)
        ->and($result->externalId)->toBe('PREF-123456')
        ->and($result->requiresRedirect())->toBeTrue()
        ->and($result->redirectUrl)->toContain('sandbox.mercadopago.com.ar');

    expect($payment->fresh()->external_id)->toBe('PREF-123456');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/checkout/preferences')
            && $request['items'][0]['title'] === 'Compra de prueba'
            && $request['items'][0]['unit_price'] === 15000.0
            && $request['payer']['email'] === 'comprador@example.com'
            && $request['external_reference'] === Payment::first()->uuid
            && $request['auto_return'] === 'approved';
    });
});

it('queries payment by external reference', function () {
    Http::fake([
        'api.mercadopago.com/v1/payments/search*' => Http::response([
            'results' => [
                [
                    'id' => 77788899,
                    'status' => 'approved',
                    'status_detail' => 'accredited',
                    'external_reference' => 'some-uuid',
                ],
            ],
            'paging' => ['total' => 1],
        ]),
    ]);

    $payment = Payment::factory()->gateway('mercadopago')->create([
        'external_id' => 'PREF-123456',
    ]);

    $result = $this->driver->query($payment);

    expect($result->status)->toBe(PaymentStatus::Paid)
        ->and($result->authorizationCode)->toBe('77788899')
        ->and($result->message)->toBe('accredited');
});

it('returns pending when no payments found in search', function () {
    Http::fake([
        'api.mercadopago.com/v1/payments/search*' => Http::response([
            'results' => [],
            'paging' => ['total' => 0],
        ]),
    ]);

    $payment = Payment::factory()->gateway('mercadopago')->create([
        'external_id' => 'PREF-123456',
    ]);

    $result = $this->driver->query($payment);

    expect($result->status)->toBe(PaymentStatus::Pending);
});

it('gets a specific payment by ID', function () {
    Http::fake([
        'api.mercadopago.com/v1/payments/77788899' => Http::response([
            'id' => 77788899,
            'status' => 'approved',
            'status_detail' => 'accredited',
            'external_reference' => 'some-uuid',
        ]),
    ]);

    $result = $this->driver->getPayment(77788899);

    expect($result->status)->toBe(PaymentStatus::Paid)
        ->and($result->authorizationCode)->toBe('77788899');
});

it('maps all MercadoPago statuses correctly', function () {
    $statuses = [
        'approved' => PaymentStatus::Paid,
        'authorized' => PaymentStatus::Authorized,
        'pending' => PaymentStatus::Pending,
        'in_process' => PaymentStatus::Pending,
        'in_mediation' => PaymentStatus::Pending,
        'rejected' => PaymentStatus::Failed,
        'cancelled' => PaymentStatus::Failed,
        'refunded' => PaymentStatus::Refunded,
        'charged_back' => PaymentStatus::Reversed,
    ];

    foreach ($statuses as $mpStatus => $expected) {
        Http::fake([
            'api.mercadopago.com/v1/payments/1' => Http::response([
                'id' => 1,
                'status' => $mpStatus,
                'external_reference' => 'test',
            ]),
        ]);

        $result = $this->driver->getPayment(1);
        expect($result->status)->toBe($expected, "Failed for MP status: {$mpStatus}");
    }
});

it('refunds a payment fully', function () {
    Http::fake([
        'api.mercadopago.com/v1/payments/77788899/refunds' => Http::response([
            'id' => 99001122,
            'status' => 'approved',
            'amount' => 15000,
        ]),
    ]);

    $payment = Payment::factory()->gateway('mercadopago')->paid()->create([
        'authorization_code' => '77788899',
        'metadata' => ['mp_payment_id' => '77788899'],
    ]);

    $result = $this->driver->refund($payment);

    expect($result->isRefunded())->toBeTrue()
        ->and($result->refundId)->toBe('99001122');
});

it('refunds a payment partially', function () {
    Http::fake([
        'api.mercadopago.com/v1/payments/77788899/refunds' => Http::response([
            'id' => 99001123,
            'status' => 'approved',
            'amount' => 5000,
        ]),
    ]);

    $payment = Payment::factory()->gateway('mercadopago')->paid()->create([
        'authorization_code' => '77788899',
        'metadata' => ['mp_payment_id' => '77788899'],
    ]);

    $partialAmount = Money::fromDecimal(5000, \Arca\PaymentGateways\Enums\Currency::CLP);
    $result = $this->driver->refund($payment, $partialAmount);

    expect($result->isRefunded())->toBeTrue()
        ->and($result->refundedAmount)->toBe($partialAmount);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/refunds')
            && $request['amount'] === 5000.0;
    });
});

it('returns error when no payment ID for refund', function () {
    $payment = Payment::factory()->gateway('mercadopago')->paid()->create([
        'authorization_code' => null,
        'metadata' => [],
    ]);

    $result = $this->driver->refund($payment);

    expect($result->isRefunded())->toBeFalse()
        ->and($result->message)->toContain('No MercadoPago payment ID');
});

it('throws on API failure during charge', function () {
    Http::fake([
        'api.mercadopago.com/checkout/preferences' => Http::response('Unauthorized', 401),
    ]);

    $payment = Payment::factory()->gateway('mercadopago')->create();
    $intent = new ChargeIntent(
        money: Money::fromDecimal(1000, \Arca\PaymentGateways\Enums\Currency::CLP),
        description: 'Test',
        urls: new ReturnUrls(returnUrl: 'https://example.com'),
        customer: new Customer(email: 'test@example.com'),
    );

    $this->driver->charge($payment, $intent);
})->throws(GatewayCommunicationException::class);

it('verifies webhook with data.id format', function () {
    $request = \Illuminate\Http\Request::create('/webhook', 'POST', [
        'data' => ['id' => '12345'],
        'type' => 'payment',
    ]);

    expect($this->driver->verifyWebhook($request))->toBeTrue();
});

it('verifies webhook with IPN format', function () {
    $request = \Illuminate\Http\Request::create('/webhook', 'POST', [
        'topic' => 'payment',
        'id' => '12345',
    ]);

    expect($this->driver->verifyWebhook($request))->toBeTrue();
});

it('rejects invalid webhook', function () {
    $request = \Illuminate\Http\Request::create('/webhook', 'POST', [
        'random' => 'data',
    ]);

    expect($this->driver->verifyWebhook($request))->toBeFalse();
});

it('handles webhook and updates payment', function () {
    Http::fake([
        'api.mercadopago.com/v1/payments/12345' => Http::response([
            'id' => 12345,
            'status' => 'approved',
            'status_detail' => 'accredited',
            'external_reference' => null,
        ]),
    ]);

    $payment = Payment::factory()->gateway('mercadopago')->create();

    Http::fake([
        'api.mercadopago.com/v1/payments/12345' => Http::response([
            'id' => 12345,
            'status' => 'approved',
            'status_detail' => 'accredited',
            'external_reference' => $payment->uuid,
        ]),
    ]);

    $request = \Illuminate\Http\Request::create('/webhook', 'POST', [
        'data' => ['id' => '12345'],
        'type' => 'payment',
    ]);

    $this->driver->handleWebhook($request);

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Paid)
        ->and($payment->paid_at)->not->toBeNull()
        ->and($payment->metadata['mp_payment_id'])->toBe('12345');
});

it('ignores webhook for non-payment topics', function () {
    $request = \Illuminate\Http\Request::create('/webhook', 'POST', [
        'data' => ['id' => '12345'],
        'type' => 'merchant_order',
    ]);

    $this->driver->handleWebhook($request);

    // No exception, no action
    expect(true)->toBeTrue();
});

it('processes return URL with payment_id', function () {
    Http::fake([
        'api.mercadopago.com/v1/payments/12345' => Http::response([
            'id' => 12345,
            'status' => 'approved',
            'status_detail' => 'accredited',
            'external_reference' => 'some-uuid',
        ]),
    ]);

    $payment = Payment::factory()->gateway('mercadopago')->create();

    $request = \Illuminate\Http\Request::create('/return', 'GET', [
        'payment_id' => '12345',
        'status' => 'approved',
        'external_reference' => $payment->uuid,
    ]);

    $result = $this->driver->processReturn($request, $payment);

    expect($result->status)->toBe(PaymentStatus::Paid)
        ->and($payment->fresh()->metadata['mp_payment_id'])->toBe('12345');
});

it('processes return URL without payment_id using status param', function () {
    $payment = Payment::factory()->gateway('mercadopago')->create([
        'external_id' => 'PREF-123',
    ]);

    $request = \Illuminate\Http\Request::create('/return', 'GET', [
        'status' => 'rejected',
        'external_reference' => $payment->uuid,
    ]);

    $result = $this->driver->processReturn($request, $payment);

    expect($result->status)->toBe(PaymentStatus::Failed);
});
