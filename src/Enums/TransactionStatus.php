<?php

namespace Arca\PaymentGateways\Enums;

enum TransactionStatus: string
{
    case USER_ABORTED = 'userAborted';
    case ERROR_OCCURRED = 'errorOccurred';
    case USER_REDIRECTED_IDLE = 'userRedirectedIdle';
    case NORMAL_PAYMENT_FLOW = 'normalPaymentFlow';
    case UNKNOWN_STATUS = 'unknownStatus';

    public function getMessage(): string
    {
        return match ($this) {
            self::USER_ABORTED => 'El usuario abortó el pago.',
            self::ERROR_OCCURRED => 'Ocurrió un error en el formulario de pago.',
            self::USER_REDIRECTED_IDLE => 'El usuario estuvo 10 minutos sin actividad en el formulario de pago y la transacción se canceló automáticamente (por timeout).',
            self::NORMAL_PAYMENT_FLOW => 'Es un flujo de pago normal. Viene solo token_ws.',
            default => 'Estado desconocido.',
        };
    }
}
