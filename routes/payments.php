<?php

use Arca\PaymentGateways\Http\Controllers\CheckoutController;
use Arca\PaymentGateways\Http\Controllers\WebhookController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::middleware(config('payment-gateways.routes.middleware', ['web']))
    ->prefix(config('payment-gateways.routes.prefix', 'payments'))
    ->group(function () {
        Route::get('{payment}', [CheckoutController::class, 'show'])->name('payments.show');
        // Gateways (Flow, Niubiz, Webpay on abort) post the buyer back from their own domain.
        Route::match(['get', 'post'], '{payment}/commit', [CheckoutController::class, 'commit'])
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->name('payments.commit');
        Route::get('{payment}/success', [CheckoutController::class, 'success'])->name('payments.success');
        Route::get('{payment}/pending', [CheckoutController::class, 'pending'])->name('payments.pending');
        Route::get('{payment}/failed', [CheckoutController::class, 'failed'])->name('payments.failed');
    });

Route::middleware(config('payment-gateways.webhook.middleware', []))
    ->prefix(config('payment-gateways.webhook.prefix', 'payments/webhooks'))
    ->group(function () {
        Route::match(['get', 'post'], '{gateway}', [WebhookController::class, 'handle'])->name('payments.webhook');
    });
