<?php

use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\Facades\PaymentGateways;
use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\ValueObjects\Money;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

beforeEach(function () {
    $this->responses = new MockHandler;
    $this->sent = [];

    $stack = HandlerStack::create($this->responses);
    $stack->push(Middleware::history($this->sent));

    PaymentGateways::driver('getnet')->usingHttpClient(new Client(['handler' => $stack]));
});

function getnetSession(Payment $payment, string $status, array $overrides = []): Response
{
    $total = $overrides['total'] ?? 15000;
    $reference = $overrides['reference'] ?? str_replace('-', '', $payment->uuid);

    return new Response(200, [], json_encode([
        'requestId' => 7001,
        'status' => ['status' => $status, 'reason' => '00', 'message' => 'La petición ha sido procesada', 'date' => '2026-10-15T10:00:00-03:00'],
        'request' => [
            'locale' => 'es_CL',
            'payment' => [
                'reference' => $reference,
                'description' => 'Order #1',
                'amount' => ['currency' => 'CLP', 'total' => $total],
            ],
            'returnUrl' => 'https://shop.cl/return',
            'ipAddress' => '127.0.0.1',
            'userAgent' => 'test',
            'expiration' => '2026-10-15T11:00:00-03:00',
        ],
        'payment' => $status === 'PENDING' ? [] : [[
            'status' => ['status' => $status, 'reason' => $status === 'APPROVED' ? '00' : '05', 'message' => $status === 'APPROVED' ? 'Aprobada' : 'Rechazada', 'date' => '2026-10-15T10:00:00-03:00'],
            'internalReference' => 99001,
            'reference' => $reference,
            'paymentMethod' => 'visa',
            'paymentMethodName' => 'Visa',
            'issuerName' => 'Banco',
            'authorization' => '999999',
            'receipt' => '1234',
            'franchise' => 'visa',
            'refunded' => false,
            'amount' => ['from' => ['currency' => 'CLP', 'total' => $total], 'to' => ['currency' => 'CLP', 'total' => $total], 'factor' => 1],
        ]],
    ]));
}

function startGetnetCheckout(): Payment
{
    test()->responses->append(new Response(200, [], json_encode([
        'status' => ['status' => 'OK', 'reason' => 'PC', 'message' => 'La petición se ha procesado correctamente', 'date' => '2026-10-15T10:00:00-03:00'],
        'requestId' => 7001,
        'processUrl' => 'https://checkout.test.getnet.cl/spa/session/7001/abc',
    ])));

    $payment = PaymentGateways::create('getnet', Money::CLP(15000), 'Order #1');
    test()->get($payment->checkoutUrl())->assertRedirect('https://checkout.test.getnet.cl/spa/session/7001/abc');

    return $payment->refresh();
}

it('creates a session with the payment reference', function () {
    $payment = startGetnetCheckout();

    $body = json_decode((string) $this->sent[0]['request']->getBody(), true);

    expect($payment->external_id)->toBe('7001')
        ->and((string) $this->sent[0]['request']->getUri())->toBe('https://checkout.test.getnet.cl/api/session')
        ->and($body['payment']['reference'])->toBe(str_replace('-', '', $payment->uuid))
        ->and($body['payment']['amount'])->toBe(['currency' => 'CLP', 'total' => 15000])
        ->and($body['returnUrl'])->toBe(route('payments.commit', $payment))
        ->and($body['auth']['login'])->toBe('test-login');
});

it('approves the payment when the buyer comes back', function () {
    $payment = startGetnetCheckout();
    $this->responses->append(getnetSession($payment, 'APPROVED'));

    $this->get(route('payments.commit', $payment))->assertRedirect(route('payments.success', $payment));

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Paid)
        ->and($payment->authorization_code)->toBe('999999')
        ->and($payment->transaction_id)->toBe('99001');
});

it('keeps the payment pending while the session is pending', function () {
    $payment = startGetnetCheckout();
    $this->responses->append(getnetSession($payment, 'PENDING'));

    $this->get(route('payments.commit', $payment))->assertRedirect(route('payments.pending', $payment));
});

it('fails a rejected session with its message', function () {
    $payment = startGetnetCheckout();
    $this->responses->append(getnetSession($payment, 'REJECTED'));

    $this->get(route('payments.commit', $payment))->assertRedirect(route('payments.failed', $payment));

    expect($payment->fresh()->failureMessage())->toBe('Rechazada');
});

it('refuses a session of another order or for less', function (array $overrides) {
    $payment = startGetnetCheckout();
    $this->responses->append(getnetSession($payment, 'APPROVED', $overrides));

    $this->get(route('payments.commit', $payment))->assertStatus(502);

    expect($payment->fresh()->isPending())->toBeTrue();
})->with([
    'other order' => [['reference' => 'other']],
    'lower amount' => [['total' => 10]],
]);

it('processes signed notifications', function () {
    $payment = startGetnetCheckout();
    $this->responses->append(getnetSession($payment, 'APPROVED'));
    $date = '2026-10-15T10:00:00-03:00';

    $this->postJson(route('payments.webhook', 'getnet'), [
        'status' => ['status' => 'APPROVED', 'reason' => '00', 'message' => 'Aprobada', 'date' => $date],
        'requestId' => 7001,
        'reference' => str_replace('-', '', $payment->uuid),
        'signature' => sha1('7001APPROVED'.$date.'test-tran-key'),
    ])->assertOk()->assertJson(['message' => 'ok']);

    expect($payment->fresh()->isPaid())->toBeTrue();
});

it('accepts sha256 signatures', function () {
    $payment = startGetnetCheckout();
    $this->responses->append(getnetSession($payment, 'APPROVED'));
    $date = '2026-10-15T10:00:00-03:00';

    $this->postJson(route('payments.webhook', 'getnet'), [
        'status' => ['status' => 'APPROVED', 'date' => $date],
        'requestId' => 7001,
        'signature' => 'sha256:'.hash('sha256', '7001APPROVED'.$date.'test-tran-key'),
    ])->assertOk();
});

it('refuses notifications with a wrong signature', function () {
    $this->postJson(route('payments.webhook', 'getnet'), [
        'status' => ['status' => 'APPROVED', 'date' => '2026-10-15T10:00:00-03:00'],
        'requestId' => 7001,
        'signature' => sha1('forged'),
    ])->assertUnauthorized();
});

it('reverses an approved payment', function () {
    $payment = Payment::factory()->gateway('getnet')->paid()->create(['amount' => 15000, 'transaction_id' => '99001']);
    $this->responses->append(new Response(200, [], json_encode([
        'status' => ['status' => 'APPROVED', 'reason' => '00', 'message' => 'Reversada', 'date' => '2026-10-15T10:00:00-03:00'],
        'payment' => [
            'status' => ['status' => 'APPROVED', 'reason' => '00', 'message' => 'Reversada', 'date' => '2026-10-15T10:00:00-03:00'],
            'internalReference' => 99002,
            'reference' => 'ref',
            'authorization' => '000000',
        ],
    ])));

    PaymentGateways::refund($payment);

    expect($payment->isRefunded())->toBeTrue()
        ->and(json_decode((string) $this->sent[0]['request']->getBody(), true)['internalReference'])->toBe('99001');
});
