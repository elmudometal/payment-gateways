<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pago rechazado</title>
</head>
<body>
    <div style="max-width: 600px; margin: 2rem auto; font-family: sans-serif; text-align: center;">
        <h1 style="color: #dc2626;">Pago rechazado</h1>
        <p><strong>Monto:</strong> {{ number_format($payment->money()->toDecimal(), $payment->currency->minorUnit()) }} {{ $payment->currency->value }}</p>
        <p><strong>Gateway:</strong> {{ $payment->gateway }}</p>
        @if(isset($payment->metadata['voucher']['message']))
            <p><strong>Motivo:</strong> {{ $payment->metadata['voucher']['message'] }}</p>
        @endif
    </div>
</body>
</html>
