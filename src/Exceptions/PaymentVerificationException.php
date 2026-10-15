<?php

namespace Arca\PaymentGateways\Exceptions;

/**
 * The gateway reported a payment that does not match the local one (reference, amount or currency).
 */
class PaymentVerificationException extends GatewayException {}
