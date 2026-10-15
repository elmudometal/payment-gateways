<?php

use Arca\PaymentGateways\Enums\Currency;
use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\Exceptions\GatewayException;
use Arca\PaymentGateways\Facades\PaymentGateways;
use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\ValueObjects\Money;
use Psr\Http\Message\ResponseInterface;
use Transbank\Contracts\RequestService;
use Transbank\Utils\TransbankApiRequest;
use Transbank\Webpay\Exceptions\WebpayRequestException;
use Transbank\Webpay\Options;

/**
 * Answers Transbank API calls from a queue and records them.
 */
class FakeTransbank implements RequestService
{
    /** @var list<array{method: string, endpoint: string, payload: array, options: Options}> */
    public array $requests = [];

    /** @var list<array|Throwable> */
    public array $responses = [];

    public function request(string $method, string $endpoint, array $payload, Options $options): array
    {
        $this->requests[] = compact('method', 'endpoint', 'payload', 'options');
        $response = array_shift($this->responses) ?? throw new RuntimeException("Unexpected {$method} {$endpoint}");

        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }

    public function getLastResponse(): ?ResponseInterface
    {
        return null;
    }

    public function getLastRequest(): ?TransbankApiRequest
    {
        return null;
    }
}

beforeEach(function () {
    $this->transbank = new FakeTransbank;
    PaymentGateways::driver('webpay')->usingRequestService($this->transbank);
});

function webpayTransaction(Payment $payment, array $overrides = []): array
{
    return $overrides + [
        'vci' => 'TSY',
        'amount' => 15000,
        'status' => 'AUTHORIZED',
        'buy_order' => (string) $payment->id,
        'session_id' => str_replace('-', '', $payment->uuid),
        'card_detail' => ['card_number' => '6623'],
        'accounting_date' => '1015',
        'transaction_date' => '2026-10-15T13:00:00.000Z',
        'authorization_code' => '1213',
        'payment_type_code' => 'VN',
        'response_code' => 0,
        'installments_number' => 0,
    ];
}

function startWebpayCheckout(): Payment
{
    test()->transbank->responses[] = ['token' => 'tok-1', 'url' => 'https://webpay3gint.transbank.cl/webpayserver/initTransaction'];

    $payment = PaymentGateways::create('webpay', Money::CLP(15000), 'Order #1');
    test()->get($payment->checkoutUrl())->assertRedirect('https://webpay3gint.transbank.cl/webpayserver/initTransaction?token_ws=tok-1');

    return $payment->refresh();
}

it('creates a transaction with the integration credentials in sandbox', function () {
    $payment = startWebpayCheckout();
    $request = $this->transbank->requests[0];

    expect($payment->external_id)->toBe('tok-1')
        ->and($request['method'])->toBe('POST')
        ->and($request['payload'])->toBe([
            'buy_order' => (string) $payment->id,
            'session_id' => str_replace('-', '', $payment->uuid),
            'amount' => 15000,
            'return_url' => route('payments.commit', $payment),
        ])
        ->and($request['options']->getCommerceCode())->toBe('597055555532')
        ->and($request['options']->isProduction())->toBeFalse();
});

it('only charges CLP', function () {
    $payment = PaymentGateways::create('webpay', Money::of(1000, Currency::USD));

    PaymentGateways::charge($payment);
})->throws(GatewayException::class, 'Webpay only charges CLP');

it('needs credentials in production', function () {
    config()->set('payment-gateways.gateways.webpay.environment', 'production');
    $payment = PaymentGateways::create('webpay', Money::CLP(1000));

    $this->get($payment->checkoutUrl())->assertStatus(502);
});

it('commits the transaction when the buyer comes back', function () {
    $payment = startWebpayCheckout();
    $this->transbank->responses[] = webpayTransaction($payment);

    $this->get(route('payments.commit', $payment).'?token_ws=tok-1')
        ->assertRedirect(route('payments.success', $payment));

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Paid)
        ->and($payment->authorization_code)->toBe('1213')
        ->and($payment->receipt('payment_type'))->toBe(__('payment-gateways::webpay.payment_types.VN'))
        ->and($payment->receipt('card_last_four'))->toBe('6623')
        ->and($this->transbank->requests[1]['method'])->toBe('PUT');
});

it('fails a rejected transaction with its message', function () {
    $payment = startWebpayCheckout();
    $this->transbank->responses[] = webpayTransaction($payment, ['status' => 'FAILED', 'response_code' => -1]);

    $this->post(route('payments.commit', $payment), ['token_ws' => 'tok-1'])
        ->assertRedirect(route('payments.failed', $payment));

    expect($payment->fresh()->failureMessage())->toBe(__('payment-gateways::webpay.response_codes.-1'));
});

it('refuses the token of another payment without committing it', function () {
    $payment = startWebpayCheckout();

    $this->get(route('payments.commit', $payment).'?token_ws=someone-elses-token')->assertStatus(502);

    expect($this->transbank->requests)->toHaveCount(1)
        ->and($payment->fresh()->isPending())->toBeTrue();
});

it('refuses a transaction for less than the payment', function () {
    $payment = startWebpayCheckout();
    $this->transbank->responses[] = webpayTransaction($payment, ['amount' => 10]);

    $this->get(route('payments.commit', $payment).'?token_ws=tok-1')->assertStatus(502);

    expect($payment->fresh()->isPending())->toBeTrue();
});

it('handles the abort, timeout and error returns', function (array $params, string $reason) {
    $payment = startWebpayCheckout();
    $params = array_map(fn ($v) => $v === 'ORDER' ? (string) $payment->id : $v, $params);

    $this->post(route('payments.commit', $payment), $params)->assertRedirect(route('payments.failed', $payment));

    expect($payment->fresh()->failureMessage())->toBe(__("payment-gateways::webpay.flow.{$reason}"))
        ->and($this->transbank->requests)->toHaveCount(1);
})->with([
    'aborted' => [['TBK_TOKEN' => 'tok-1', 'TBK_ORDEN_COMPRA' => 'ORDER', 'TBK_ID_SESION' => 's'], 'aborted'],
    'timeout' => [['TBK_ORDEN_COMPRA' => 'ORDER', 'TBK_ID_SESION' => 's'], 'timeout'],
    'form error' => [['token_ws' => 'tok-1', 'TBK_TOKEN' => 'tok-1', 'TBK_ORDEN_COMPRA' => 'ORDER', 'TBK_ID_SESION' => 's'], 'error'],
]);

it('reads the status when the token was already committed', function () {
    $payment = startWebpayCheckout();
    $this->transbank->responses[] = new WebpayRequestException('Transaction already locked', 'already locked', 422);
    $this->transbank->responses[] = webpayTransaction($payment);

    $this->get(route('payments.commit', $payment).'?token_ws=tok-1')->assertRedirect(route('payments.success', $payment));
});

it('refunds the transaction', function () {
    $payment = Payment::factory()->gateway('webpay')->paid()->create(['amount' => 15000, 'external_id' => 'tok-1']);
    $this->transbank->responses[] = ['type' => 'NULLIFIED', 'authorization_code' => '123', 'nullified_amount' => 15000, 'balance' => 0, 'response_code' => 0];

    PaymentGateways::refund($payment);

    expect($payment->isRefunded())->toBeTrue()
        ->and($this->transbank->requests[0]['payload'])->toBe(['amount' => 15000]);
});

it('logs the Transbank calls as received', function () {
    $payment = startWebpayCheckout();
    $this->transbank->responses[] = webpayTransaction($payment);

    $this->get(route('payments.commit', $payment).'?token_ws=tok-1');

    $logs = $payment->logs()->where('direction', 'out')->get();

    expect($logs->pluck('type')->all())->toBe(['charge', 'complete'])
        ->and($logs[0]->url)->toBe('https://webpay3gint.transbank.cl/rswebpaytransaction/api/webpay/v1.2/transactions')
        ->and($logs[0]->request['buy_order'])->toBe((string) $payment->id)
        ->and($logs[1]->method)->toBe('PUT')
        ->and($logs[1]->response['vci'])->toBe('TSY')
        ->and($logs[1]->response['card_detail'])->toBe(['card_number' => '6623']);
});
