<?php

namespace Arca\PaymentGateways\Exceptions;

class UnsupportedFeatureException extends GatewayException
{
    public static function forFeature(string $feature, string $gateway): self
    {
        return new self(
            message: "The [{$feature}] feature is not supported by the [{$gateway}] gateway.",
            gateway: $gateway,
        );
    }
}
