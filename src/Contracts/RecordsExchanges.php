<?php

namespace Arca\PaymentGateways\Contracts;

/**
 * A driver that records its raw exchanges with the gateway for payment_logs.
 */
interface RecordsExchanges
{
    /**
     * Returns the exchanges recorded since the last call and forgets them.
     *
     * @return list<array{method: ?string, url: ?string, status_code: ?int, request: mixed, response: mixed, response_headers: ?array<string, string>, error: ?string}>
     */
    public function pullExchanges(): array;
}
