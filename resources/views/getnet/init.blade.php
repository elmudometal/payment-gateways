<section>
    <div class="row p-5">
        <div class="mx-auto col-md-8">
            <form action="{{ $url }}" method="get">
                <h4 class="mb-4 text-center"><p><strong>{{ config('payment-gateways.getnet.commerce_name') }}</strong></p></h4>
                <p class="text-center"><img src="{{ asset('vendor/payment-gateways/images/banner_getnet.png') }}" alt="Getnet Santader" class="img-responsive"/></p>
                <h2>Datos del pago:</h2>
                <p>Número de orden: {{ $payment->id }}</p>
                <p>Monto: $ {{ $payment->amount }}</p>
                <p>Detalle:</p>
                <p>{{ $payment->comments }}</p>
                <input type="submit" class="btn btn-primary" value="Ir a Pagar">
            </form>
        </div>
    </div>
</section>
