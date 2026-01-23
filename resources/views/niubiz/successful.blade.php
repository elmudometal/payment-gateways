<section>
    <div class="row p-5">
        <div class="mx-auto col-md-8">
            <h1 class="text-ok text-center">PAGO REALIZADO CON ÉXITO</h1>
            <p class="text-center"><em>Su pago ha sido procesado correctamente.</em></p>
            <br>
            <h2>DATOS DE PAGO:</h2>
            <p>Número de orden: {{ $payment->id }}</p>
            <p>{{ config('payment-gateways.niubiz.commerce_name') }}</p>
            <p>Monto: S/ {{ number_format($payment->amount, 2, '.', ',') }}</p>
            @if(isset($payment->voucher['dataMap']))
                <p>Código de autorización: {{ $payment->voucher['dataMap']['AUTHORIZATION_CODE'] ?? $payment->authorization_code }}</p>
                <p>Tarjeta: {{ $payment->voucher['dataMap']['CARD'] ?? 'N/A' }}</p>
                <p>Marca: {{ strtoupper($payment->voucher['dataMap']['BRAND'] ?? 'N/A') }}</p>
                <p>Fecha de transacción: {{ $payment->voucher['dataMap']['TRANSACTION_DATE'] ?? 'N/A' }}</p>
            @endif
            <p>Descripción:<br>
                {{ $payment->comments }}
            </p>
            <a href="/">
                Volver al inicio
            </a>
        </div>
    </div>
</section>
