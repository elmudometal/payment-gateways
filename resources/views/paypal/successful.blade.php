<section>
    <div class="row p-5">
        <div class="mx-auto col-md-8">
            <h1 class="text-ok">PAGO REALIZADO CON ÉXITO</h1>
            <p><em>Una vez que su inscripción sea verificada, recibirá un correo certificando su inscripción y con las instrucciones para participar.</em></p>
            <p>Por favor verifique su casilla de SPAM o de notificaciones.</p>
            <br>
            <h4 class="mb-4 text-center"><p><strong>{{ config('payment-gateways.paypal.commerce_name') }}</strong></p></h4>
            <h2>DATOS DE PAGO:</h2>
            <p>Número de orden: {{ $payment->id }}</p>
            <p>Monto: {{ $payment->amount }}</p>

            <p>Identificación del pago: {{ $payment->voucher['purchase_units'][0]['payments']['captures'][0]['id'] }}</p>
            <p>Identificación de la transacción: {{ $payment->voucher['id'] }}</p>
            <p>{{ config('paypal.commerce_name') }}</p>
            <p>Monto: ${{ number_format($payment->amount, 0, ',', '.') }} USD </p>
            <p>Descripción de los bienes y/o servicios:<br>
                {{ $payment->comments }}
            </p>


            <a href="/">
                Volver al inicio
            </a>
        </div>
    </div>
</section>
