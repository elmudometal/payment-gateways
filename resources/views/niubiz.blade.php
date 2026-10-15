@extends('payment-gateways::layout')

@section('title', $payment->description ?: config('app.name'))

@section('content')
    <h1>{{ $payment->description ?: config('app.name') }}</h1>
    @include('payment-gateways::partials.summary')

    <form action="{{ $action }}" method="post">
        <script
            src="{{ $jsUrl }}"
            data-sessiontoken="{{ $sessionKey }}"
            data-channel="web"
            data-merchantid="{{ $merchantId }}"
            data-purchasenumber="{{ $purchaseNumber }}"
            data-amount="{{ $amount }}"
            data-expirationminutes="20"
            data-timeouturl="{{ $timeoutUrl }}"
            @if ($logo) data-merchantlogo="{{ $logo }}" @endif
            @if ($email) data-usertoken="{{ $email }}" @endif
        ></script>
    </form>
@endsection
