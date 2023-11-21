<section>
    <div class="row p-5">
        <div class="mx-auto col-md-8">
            <h1 class="text-ok text-center">PAGO REALIZADO CON ÉXITO</h1>
            <p class="text-center"><em>Una vez que su inscripción sea verificada, recibirá un correo certificando su inscripción y con las instrucciones para participar.</em></p>
            <p class="text-center">Por favor verifique su casilla de SPAM o de notificaciones.</p>
            <br>
            <h4 class="mb-4 text-center"><p><strong>{{ config('payment-gateways.getnet.commerce_name') }}</strong></p></h4>
            <h2>DATOS DE PAGO:</h2>
            <p>Número de orden: {{ $payment->id }}</p>
            <p>Monto: {{ $payment->amount }}</p>
            <p>Código de autorización de la transacción: {{ $payment->voucher['payment'][0]['authorization'] }}</p>
            <p>Numero de Recibo: {{ $payment->voucher['payment'][0]['receipt'] }}</p>
            <p>Fecha de la transacción: {{ $payment->voucher['payment'][0]['status']['date'] }}</p>
            <p>Medio de Pago: {{ $payment->voucher['payment'][0]['paymentMethodName'] }}</p>
            <p>4 últimos dígitos de la tarjeta bancaria: *** **** **** {{ $payment->voucher['payment'][0]['processorFields'][array_search('lastDigits', array_column($payment->voucher['payment'][0]['processorFields'], 'keyword'))]['value'] }}</p>
            <p>Cantidad de cuotas: {{ $payment->voucher['payment'][0]['processorFields'][array_search('installments', array_column($payment->voucher['payment'][0]['processorFields'], 'keyword'))]['value'] }}</p>
            <p>Descripción de los bienes y/o servicios:<br>
                {{ $payment->comments }}
            </p>
            <p class="text-center"><img src="{{ asset('vendor/payment-gateways/images/banner_getnet.png') }}" alt="Getnet Santader" class="img-responsive"/></p>
            <a href="/">
                Volver al inicio
            </a>
        </div>
    </div>
</section>
