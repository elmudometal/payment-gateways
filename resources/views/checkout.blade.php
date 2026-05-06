<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pago #{{ $payment->uuid }}</title>
</head>
<body>
    <div style="max-width: 600px; margin: 2rem auto; font-family: sans-serif;">
        <h1>Procesando pago</h1>
        <p><strong>Monto:</strong> {{ number_format($payment->money()->toDecimal(), $payment->currency->minorUnit()) }} {{ $payment->currency->value }}</p>
        <p><strong>Estado:</strong> {{ $payment->status->label() }}</p>
        @if($payment->description)
            <p><strong>Descripción:</strong> {{ $payment->description }}</p>
        @endif
    </div>
</body>
</html>
