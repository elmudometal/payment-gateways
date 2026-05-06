<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pago exitoso</title>
</head>
<body>
    <div style="max-width: 600px; margin: 2rem auto; font-family: sans-serif; text-align: center;">
        <h1 style="color: #16a34a;">Pago exitoso</h1>
        <p><strong>Monto:</strong> {{ number_format($payment->money()->toDecimal(), $payment->currency->minorUnit()) }} {{ $payment->currency->value }}</p>
        @if($payment->authorization_code)
            <p><strong>Código de autorización:</strong> {{ $payment->authorization_code }}</p>
        @endif
        <p><strong>Gateway:</strong> {{ $payment->gateway }}</p>
    </div>
</body>
</html>
