<section>
    <div class="row p-5">
        <div class="mx-auto col-md-8">

            <h1 class="text-ok text-center">PAGO REALIZADO CON ÉXITO</h1>
            <p class="text-center"><em>Una vez que su inscripción sea verificada, recibirá un correo certificando su inscripción y con las instrucciones para participar.</em></p>
            <p class="text-center">Por favor verifique su casilla de SPAM o de notificaciones.</p>
            <br>
            <h2>DATOS DE PAGO:</h2>
            <p>Número de orden: {{ $payment->id }}</p>
            <p>Número de pago Flow: {{ $payment->voucher['flowOrder'] }}</p>
            <p>{{ config('payment-gateways.flow.commerce_name') }}</p>
            <p>Monto: ${{ number_format($payment->voucher['paymentData']['amount'], 0, ',', '.') }}</p>
            <p>Fecha de la transacción: {{ $payment->voucher['paymentData']['date'] }}</p>
            <p>Medio de pago: {{ $payment->voucher['paymentData']['media'] }}</p>
            <p>Estado del pago: {{ config('payment-gateways.flow.status')[$payment->voucher['status'] ?? 999] }}</p>
            <p>Descripción de los bienes y/o servicios:<br>
                {{ $payment->comments }}
            </p>
            <a href="/">
                Volver al inicio
            </a>
        </div>
    </div>
</section>
