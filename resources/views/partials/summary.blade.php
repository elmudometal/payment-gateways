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
    @if ($date = $payment->receipt('transaction_date', $payment->paid_at?->toDateTimeString()))
        <dt>{{ __('payment-gateways::messages.transaction_date') }}</dt>
        <dd>{{ $date }}</dd>
    @endif
    @if ($type = $payment->receipt('payment_type', $payment->receipt('payment_method')))
        <dt>{{ __('payment-gateways::messages.payment_type') }}</dt>
        <dd>{{ $type }}</dd>
    @endif
    @if ($payment->receipt('installments'))
        <dt>{{ __('payment-gateways::messages.installments') }}</dt>
        <dd>{{ $payment->receipt('installments') }}</dd>
    @endif
    @if ($card = $payment->receipt('card_last_four') ? '**** '.$payment->receipt('card_last_four') : $payment->receipt('card_number'))
        <dt>{{ __('payment-gateways::messages.card') }}</dt>
        <dd>{{ $card }}</dd>
    @endif
</dl>
