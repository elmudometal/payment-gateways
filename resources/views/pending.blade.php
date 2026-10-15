@extends('payment-gateways::layout')

@section('title', __('payment-gateways::messages.pending_title'))

@section('content')
    <h1 class="pending">{{ __('payment-gateways::messages.pending_title') }}</h1>
    <p>{{ __('payment-gateways::messages.pending_text') }}</p>
    @include('payment-gateways::partials.summary')
    <a class="button" href="{{ route('payments.commit', $payment) }}">{{ __('payment-gateways::messages.pending_refresh') }}</a>
@endsection
