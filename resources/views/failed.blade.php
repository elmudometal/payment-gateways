@extends('payment-gateways::layout')

@section('title', __('payment-gateways::messages.failed_title'))

@section('content')
    <h1 class="failed">{{ __('payment-gateways::messages.failed_title') }}</h1>
    @if ($error ?? $payment->failureMessage())
        <p><strong>{{ __('payment-gateways::messages.reason') }}:</strong> {{ $error ?? $payment->failureMessage() }}</p>
    @endif
    @include('payment-gateways::partials.summary')
    @if ($payment->isPending())
        <a class="button" href="{{ $payment->checkoutUrl() }}">{{ __('payment-gateways::messages.retry') }}</a>
    @endif
@endsection
