<?php

use Arca\PaymentGateways\Drivers\Flow\FlowDriver;
use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\Exceptions\GatewayException;
use Arca\PaymentGateways\Facades\PaymentGateways;
use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\ValueObjects\Customer;
use Arca\PaymentGateways\ValueObjects\Money;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

const FLOW = 'https://sandbox.flow.cl/api';

function flowStatus(Payment $payment, array $overrides = []): array
{
    return $overrides + [
        'flowOrder' => 3567899,
        'commerceOrder' => $payment->uuid,
        'status' => 2,
        'subject' => 'Order #1',
        'currency' => 'CLP',
        'amount' => 15000,
        'paymentData' => ['authorizationCode' => 'A1B2'],
        'payer' => 'buyer@example.com',
    ];
}

function startFlowCheckout(): Payment
{
    Http::fake([FLOW.'/payment/create' => Http::response(['url' => 'https://sandbox.flow.cl/app/web/pay.php', 'token' => 'flow-token', 'flowOrder' => 3567899])]);

    $payment = PaymentGateways::create('flow', Money::CLP(15000), 'Order #1', customer: new Customer(email: 'buyer@example.com'));
    test()->get($payment->checkoutUrl())->assertRedirect('https://sandbox.flow.cl/app/web/pay.php?token=flow-token');

    return $payment->refresh();
}

it('creates a signed payment', function () {
    $payment = startFlowCheckout();

    expect($payment->external_id)->toBe('flow-token');

    Http::assertSent(function (HttpRequest $request) use ($payment) {
        $data = $request->data();
        $signature = $data['s'];
        unset($data['s']);

        return $request->url() === FLOW.'/payment/create'
            && $data['commerceOrder'] === $payment->uuid
            && $data['amount'] === '15000'
            && $data['email'] === 'buyer@example.com'
            && $data['apiKey'] === 'test-api-key'
            && $data['urlReturn'] === route('payments.commit', $payment)
            && $signature === (new FlowDriver(config('payment-gateways.gateways.flow')))->sign($data);
    });
});

it('needs the buyer email', function () {
    $payment = PaymentGateways::create('flow', Money::CLP(15000));

    PaymentGateways::charge($payment);
})->throws(GatewayException::class, 'Flow needs the buyer email');

it('approves the payment when Flow posts the buyer back', function () {
    $payment = startFlowCheckout();
    Http::fake([FLOW.'/payment/getStatus*' => Http::response(flowStatus($payment))]);

    $this->post(route('payments.commit', $payment), ['token' => 'flow-token'])
        ->assertRedirect(route('payments.success', $payment));

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Paid)
        ->and($payment->transaction_id)->toBe('3567899')
        ->and($payment->authorization_code)->toBe('A1B2');
});

it('refuses a token of another payment', function () {
    $payment = startFlowCheckout();

    $this->post(route('payments.commit', $payment), ['token' => 'other-token'])->assertStatus(502);

    expect($payment->fresh()->isPending())->toBeTrue();
});

it('refuses a status for another order or a lower amount', function (array $overrides) {
    $payment = startFlowCheckout();
    Http::fake([FLOW.'/payment/getStatus*' => Http::response(flowStatus($payment, $overrides))]);

    $this->post(route('payments.commit', $payment), ['token' => 'flow-token'])->assertStatus(502);

    expect($payment->fresh()->isPending())->toBeTrue();
})->with([
    'other order' => [['commerceOrder' => 'other']],
    'lower amount' => [['amount' => 100]],
]);

it('maps Flow statuses', function (int $flowStatus, PaymentStatus $expected) {
    $payment = startFlowCheckout();
    Http::fake([FLOW.'/payment/getStatus*' => Http::response(flowStatus($payment, ['status' => $flowStatus]))]);

    expect(PaymentGateways::driver('flow')->query($payment)->status)->toBe($expected);
})->with([
    [1, PaymentStatus::Pending],
    [2, PaymentStatus::Paid],
    [3, PaymentStatus::Failed],
    [4, PaymentStatus::Failed],
]);

it('confirms the payment from the Flow notification', function () {
    $payment = startFlowCheckout();
    Http::fake([FLOW.'/payment/getStatus*' => Http::response(flowStatus($payment))]);

    $this->post(route('payments.webhook', 'flow'), ['token' => 'flow-token'])->assertOk();

    expect($payment->fresh()->isPaid())->toBeTrue();
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'token=flow-token') && str_contains($r->url(), 's='));
});

it('refuses notifications without token', function () {
    $this->post(route('payments.webhook', 'flow'))->assertUnauthorized();
});
