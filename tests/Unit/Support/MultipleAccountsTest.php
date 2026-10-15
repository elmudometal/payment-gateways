<?php

use Arca\PaymentGateways\Events\PaymentApproved;
use Arca\PaymentGateways\Facades\PaymentGateways;
use Arca\PaymentGateways\ValueObjects\Money;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use Transbank\Contracts\RequestService;
use Transbank\Utils\TransbankApiRequest;
use Transbank\Webpay\Options;

beforeEach(function () {
    config()->set('payment-gateways.gateways.webpay_courses', [
        'driver' => 'webpay',
        'environment' => 'production',
        'commerce_code' => '597000000001',
        'api_key' => 'courses-key',
    ]);
    config()->set('payment-gateways.gateways.webpay_workshops', [
        'driver' => 'webpay',
        'environment' => 'production',
        'commerce_code' => '597000000002',
        'api_key' => 'workshops-key',
    ]);
});

function recordingTransbank(): RequestService
{
    return new class implements RequestService
    {
        /** @var list<Options> */
        public array $options = [];

        public function request(string $method, string $endpoint, array $payload, Options $options): array
        {
            $this->options[] = $options;

            return ['token' => 'tok', 'url' => 'https://webpay3g.transbank.cl/webpayserver/initTransaction'];
        }

        public function getLastResponse(): ?ResponseInterface
        {
            return null;
        }

        public function getLastRequest(): ?TransbankApiRequest
        {
            return null;
        }
    };
}

it('charges each Webpay account with its own commerce code', function () {
    $courses = recordingTransbank();
    $workshops = recordingTransbank();
    PaymentGateways::driver('webpay_courses')->usingRequestService($courses);
    PaymentGateways::driver('webpay_workshops')->usingRequestService($workshops);

    $course = PaymentGateways::create('webpay_courses', Money::CLP(50000), 'Laravel course');
    $workshop = PaymentGateways::create('webpay_workshops', Money::CLP(20000), 'Pottery workshop');

    PaymentGateways::charge($course);
    PaymentGateways::charge($workshop);

    expect($courses->options[0]->getCommerceCode())->toBe('597000000001')
        ->and($courses->options[0]->getApiKey())->toBe('courses-key')
        ->and($courses->options[0]->isProduction())->toBeTrue()
        ->and($workshops->options[0]->getCommerceCode())->toBe('597000000002')
        ->and($course->gateway)->toBe('webpay_courses')
        ->and($workshop->gateway)->toBe('webpay_workshops');
});

it('routes notifications to the account the payment belongs to', function () {
    Event::fake([PaymentApproved::class]);
    config()->set('payment-gateways.gateways.mercadopago_pe', [
        'driver' => 'mercadopago',
        'environment' => 'sandbox',
        'access_token' => 'PE-token',
    ]);
    Http::fake(['https://api.mercadopago.com/checkout/preferences' => Http::response(['id' => 'pref-pe', 'init_point' => 'https://mp.test/pe'])]);

    $payment = PaymentGateways::create('mercadopago_pe', Money::PEN(9900), 'Order PE');
    PaymentGateways::charge($payment);

    Http::fake(['https://api.mercadopago.com/v1/payments/555' => Http::response([
        'id' => 555,
        'status' => 'approved',
        'external_reference' => $payment->uuid,
        'transaction_amount' => 99,
        'currency_id' => 'PEN',
    ])]);

    $this->postJson(route('payments.webhook', 'mercadopago_pe').'?data.id=555&type=payment')
        ->assertOk()
        ->assertJson(['message' => 'ok']);

    expect($payment->fresh()->isPaid())->toBeTrue();
    Event::assertDispatched(PaymentApproved::class, fn ($e) => $e->payment->gateway === 'mercadopago_pe');
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer PE-token'));
});

it('does not let one account confirm the payments of another', function () {
    config()->set('payment-gateways.gateways.mercadopago_pe', ['driver' => 'mercadopago', 'environment' => 'sandbox', 'access_token' => 'PE-token']);
    Http::fake(['https://api.mercadopago.com/checkout/preferences' => Http::response(['id' => 'pref', 'init_point' => 'https://mp.test'])]);

    $payment = PaymentGateways::create('mercadopago_pe', Money::PEN(9900));
    PaymentGateways::charge($payment);

    Http::fake(['https://api.mercadopago.com/v1/payments/555' => Http::response([
        'id' => 555, 'status' => 'approved', 'external_reference' => $payment->uuid,
        'transaction_amount' => 99, 'currency_id' => 'PEN',
    ])]);

    // Notification sent to the other account's URL: that account does not own the payment.
    $this->withHeaders(['x-signature' => 'ts=1,v1='.hash_hmac('sha256', 'id:555;ts:1;', 'mp-secret')])
        ->postJson(route('payments.webhook', 'mercadopago').'?data.id=555&type=payment')
        ->assertOk()
        ->assertJson(['message' => 'ignored']);

    expect($payment->fresh()->isPending())->toBeTrue();
});
