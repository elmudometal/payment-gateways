<section>
    <div class="row p-5">
        <div class="mx-auto col-md-8">
            <form action="{{ $url }}" id="auto" method="post">
                <h4 class="mb-4 text-center"><p><strong>{{ config('payment-gateways.webpay.commerce_name') }}</strong></p></h4>
                <p class="text-center"><img src="{{ asset('vendor/payment-gateways/images/banner_webpay.png') }}" alt="WebPay de Transbank" class="img-responsive"/></p>
                <h4>Datos del pago:</h4>
                <p>Número de orden: {{ $payment->id }}</p>
                <p>Monto: $ {{ $payment->amount }}</p>
                <p>Detalle:</p>
                <p>{{ $payment->comments }}</p>
                <input type="hidden" name="token_ws" value={{ $token }} />
                <input type="submit" class="btn btn-primary" value="Ir a Pagar">
            </form>
        </div>
    </div>
</section>
