<section>
    <div class="row p-5">
        <div class="mx-auto col-md-8">

            <h1 class="text-center">PAGO PENDIENTE</h1>
            <p class="text-center"><em>Su pago está siendo procesado. Le notificaremos cuando se confirme.</em></p>
            <br>
            <h2>DATOS DE PAGO:</h2>
            <p>Número de orden: {{ $payment->id }}</p>
            <p>Número de pago Mercado Pago: {{ $payment->voucher['id'] ?? '' }}</p>
            <p>{{ config('payment-gateways.mercadopago.commerce_name') }}</p>
            <p>Monto: ${{ number_format($payment->amount, 0, ',', '.') }}</p>
            <p>Medio de pago: {{ $payment->voucher['payment_method_id'] ?? '' }}</p>
            <p>Descripción de los bienes y/o servicios:<br>
                {{ $payment->comments }}
            </p>
            <a href="/">
                Volver al inicio
            </a>
        </div>
    </div>
</section>
