<?php

use Arca\PaymentGateways\Http\Controllers\CheckoutController;
use Arca\PaymentGateways\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

$prefix = config('payment-gateways.routes.prefix', 'payments');
$middleware = config('payment-gateways.routes.middleware', ['web']);
$webhookPrefix = config('payment-gateways.webhook.route_prefix', 'payments/webhooks');

Route::middleware($middleware)->prefix($prefix)->group(function () {
    Route::get('{payment}', [CheckoutController::class, 'show'])->name('payments.show');
    Route::match(['get', 'post'], '{payment}/commit', [CheckoutController::class, 'commit'])->name('payments.commit');
    Route::get('{payment}/success', [CheckoutController::class, 'success'])->name('payments.success');
    Route::get('{payment}/failed', [CheckoutController::class, 'failed'])->name('payments.failed');
});

Route::prefix($webhookPrefix)->group(function () {
    Route::match(['get', 'post'], '{gateway}', [WebhookController::class, 'handle'])->name('payments.webhook');
});
