<?php

use Arca\PaymentGateways\Support\Url;

it('only treats public https urls as reachable by a gateway', function (string $url, bool $public) {
    expect(Url::isPublic($url))->toBe($public);
})->with([
    ['https://shop.cl/payments/webhooks/mercadopago', true],
    ['https://8.8.8.8/hook', true],
    ['http://shop.cl/hook', false],
    ['https://localhost/hook', false],
    ['https://127.0.0.1/hook', false],
    ['https://192.168.1.20/hook', false],
    ['https://10.0.0.5/hook', false],
    ['https://[::1]/hook', false],
    ['https://shop.test/hook', false],
    ['https://app.localhost/hook', false],
    ['https://printer.local/hook', false],
]);
