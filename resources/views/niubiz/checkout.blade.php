<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pago con Niubiz</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f7fa; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .checkout-container { background: white; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,0.1); max-width: 500px; width: 100%; padding: 32px; }
        .header { text-align: center; margin-bottom: 24px; }
        .header h1 { font-size: 24px; color: #1a1a2e; margin-bottom: 8px; }
        .header .amount { font-size: 32px; font-weight: 700; color: #00a650; }
        .header .order { font-size: 14px; color: #666; margin-top: 8px; }
        #niubiz-form { min-height: 300px; }
        .loading { text-align: center; padding: 40px; color: #666; }
        .error { background: #fee2e2; color: #dc2626; padding: 16px; border-radius: 8px; margin-top: 16px; }
        .secure-badge { display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: 24px; font-size: 12px; color: #666; }
        .secure-badge svg { width: 16px; height: 16px; }
    </style>
</head>
<body>
    <div class="checkout-container">
        <div class="header">
            <h1>{{ config('payment-gateways.niubiz.commerce_name') }}</h1>
            <div class="amount">S/ {{ number_format($payment->amount, 2, '.', ',') }}</div>
            <div class="order">Orden #{{ $payment->id }}</div>
        </div>

        <div id="niubiz-form">
            <div class="loading">Cargando formulario de pago...</div>
        </div>

        <div id="error-message" class="error" style="display: none;"></div>

        <div class="secure-badge">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
            </svg>
            Pago seguro procesado por Niubiz
        </div>
    </div>

    <script src="{{ $jsUrl }}"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const configuration = {
                sessiontoken: '{{ $sessionToken }}',
                channel: 'web',
                merchantid: '{{ $merchantId }}',
                purchasenumber: '{{ $payment->id }}',
                amount: '{{ $payment->amount }}',
                cardholdername: '',
                cardholderlastname: '',
                cardholderemail: '{{ $payment->model?->email ?? '' }}',
                usertoken: '',
                expirationminutes: '20',
                timeouturl: '{{ route('niubiz.rejected', $payment->uuid) }}',
                merchantlogo: '',
                formbuttoncolor: '#00a650',
                action: '{{ $authorizeUrl }}',
                complete: function(params) {
                    // Crear form y enviar el transactionToken
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '{{ $authorizeUrl }}';

                    const tokenInput = document.createElement('input');
                    tokenInput.type = 'hidden';
                    tokenInput.name = 'transactionToken';
                    tokenInput.value = params.transactionToken;
                    form.appendChild(tokenInput);

                    const csrfInput = document.createElement('input');
                    csrfInput.type = 'hidden';
                    csrfInput.name = '_token';
                    csrfInput.value = '{{ csrf_token() }}';
                    form.appendChild(csrfInput);

                    document.body.appendChild(form);
                    form.submit();
                }
            };

            try {
                VisanetCheckout.configure(configuration);
                VisanetCheckout.open();
            } catch (e) {
                document.getElementById('error-message').textContent = 'Error al cargar el formulario de pago: ' + e.message;
                document.getElementById('error-message').style.display = 'block';
            }
        });
    </script>
</body>
</html>
