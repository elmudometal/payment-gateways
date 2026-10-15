<?php

use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\Facades\PaymentGateways;
use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\ValueObjects\Money;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

const NIUBIZ = 'https://apisandbox.vnforappstest.com';

function niubizAuthorization(Payment $payment, array $overrides = []): array
{
    return [
        'header' => ['ecoreTransactionUUID' => 'abc'],
        'order' => $overrides + [
            'purchaseNumber' => (string) $payment->id,
            'amount' => 150.5,
            'currency' => 'PEN',
            'authorizationCode' => '173424',
            'actionCode' => '000',
            'transactionId' => '993242311341',
        ],
        'dataMap' => [
            'ACTION_CODE' => $overrides['actionCode'] ?? '000',
            'STATUS' => 'Authorized',
            'AUTHORIZATION_CODE' => '173424',
            'TRANSACTION_ID' => '993242311341',
            'CARD' => '455170******8059',
            'BRAND' => 'visa',
        ],
    ];
}

function startNiubizCheckout(): Payment
{
    Http::fake([
        NIUBIZ.'/api.security/v1/security' => Http::response('access-token'),
        NIUBIZ.'/api.ecommerce/v2/ecommerce/token/session/*' => Http::response(['sessionKey' => 'session-key', 'expirationTime' => 1]),
    ]);

    $payment = PaymentGateways::create('niubiz', Money::PEN(15050), 'Order #1');

    test()->get($payment->checkoutUrl())
        ->assertOk()
        ->assertSee('data-sessiontoken="session-key"', false)
        ->assertSee('data-purchasenumber="'.$payment->id.'"', false)
        ->assertSee('data-amount="150.50"', false)
        ->assertSee('action="'.route('payments.commit', $payment).'"', false);

    return $payment->refresh();
}

it('renders the Niubiz form with a session token', function () {
    $payment = startNiubizCheckout();

    expect($payment->external_id)->toBe('session-key');

    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/token/session/456879852')
        && $r->hasHeader('Authorization', 'access-token')
        && $r['amount'] === 150.5);
});

it('authorizes the transaction token posted by the form', function () {
    $payment = startNiubizCheckout();
    Http::fake([NIUBIZ.'/api.authorization/v3/authorization/ecommerce/*' => Http::response(niubizAuthorization($payment))]);

    $this->post(route('payments.commit', $payment), ['transactionToken' => 'tx-token', 'channel' => 'web'])
        ->assertRedirect(route('payments.success', $payment));

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Paid)
        ->and($payment->authorization_code)->toBe('173424')
        ->and($payment->transaction_id)->toBe('993242311341');

    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/authorization/ecommerce/456879852')
        && $r['order']['tokenId'] === 'tx-token'
        && $r['order']['purchaseNumber'] === (string) $payment->id
        && $r['order']['currency'] === 'PEN');
});

it('rejects a declined card with its message', function () {
    $payment = startNiubizCheckout();
    Http::fake([NIUBIZ.'/api.authorization/v3/authorization/ecommerce/*' => Http::response([
        'errorCode' => 400,
        'errorMessage' => 'NOT AUTHORIZED',
        'data' => ['ACTION_CODE' => '116', 'ACTION_DESCRIPTION' => 'Fondos insuficientes', 'STATUS' => 'Not Authorized'],
    ], 400)]);

    $this->post(route('payments.commit', $payment), ['transactionToken' => 'tx-token'])
        ->assertRedirect(route('payments.failed', $payment));

    expect($payment->fresh()->failureMessage())->toBe(__('payment-gateways::niubiz.action_codes.116'));
});

it('refuses an authorization for a lower amount', function () {
    $payment = startNiubizCheckout();
    Http::fake([NIUBIZ.'/api.authorization/v3/authorization/ecommerce/*' => Http::response(niubizAuthorization($payment, ['amount' => 1]))]);

    $this->post(route('payments.commit', $payment), ['transactionToken' => 'tx-token'])->assertStatus(502);

    expect($payment->fresh()->isPending())->toBeTrue();
});

it('fails the payment when the form times out', function () {
    $payment = startNiubizCheckout();

    $this->get(route('payments.commit', $payment).'?cancelled=1')
        ->assertRedirect(route('payments.failed', $payment));
});
