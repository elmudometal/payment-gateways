@extends('payment-gateways::layout')

@section('title', __('payment-gateways::messages.success_title'))

@section('content')
    <h1 class="success">{{ __('payment-gateways::messages.success_title') }}</h1>
    @include('payment-gateways::partials.summary')
@endsection
