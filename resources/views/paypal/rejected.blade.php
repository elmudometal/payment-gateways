<section>
    <div class="row p-5">
        <div class="mx-auto col-md-8">
            <h1 class="text-not-ok">PAGO RECHAZADO</h1>
            <p><em>Ha ocurrido un error durante su Pago</em></p>
            <h4>Datos del Pago:</h4>
            <p>Número de orden: {{ $payment->id }}</p>
            <h4 class="mb-4 text-center"><p><strong>{{ config('payment-gateways.getnet.commerce_name') }}</strong></p></h4>
            <h2>Error:</h2>
            <p>
                {{ $error }}
            </p>
            <h2>Las posibles causas de este rechazo son: </h2>
            <ul>
                <li>
                    Error en el ingreso de los datos de su tarjeta de Crédito o Débito (fecha y/o código de
                    seguridad)
                </li>
                <li>
                    Su tarjeta de Crédito o Débito no cuenta con saldo suficiente.
                </li>
                <li>
                    Tarjeta aún no habilitada en el sistema financiero.
                </li>
            </ul>

            <a href="/">
                Volver al inicio
            </a>
        </div>
    </div>
</section>
