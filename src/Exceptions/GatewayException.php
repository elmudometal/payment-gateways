<?php

namespace Arca\PaymentGateways\Exceptions;

use RuntimeException;

class GatewayException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $gateway = '',
        public readonly array $context = [],
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
