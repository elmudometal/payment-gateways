<dl>
    <dt>{{ __('payment-gateways::messages.amount') }}</dt>
    <dd>{{ $payment->money()->format() }}</dd>
    @if ($payment->description)
        <dt>{{ __('payment-gateways::messages.description') }}</dt>
        <dd>{{ $payment->description }}</dd>
    @endif
    <dt>{{ __('payment-gateways::messages.reference') }}</dt>
    <dd>{{ $payment->uuid }}</dd>
    @if ($payment->authorization_code)
        <dt>{{ __('payment-gateways::messages.authorization_code') }}</dt>
        <dd>{{ $payment->authorization_code }}</dd>
    @endif
</dl>
