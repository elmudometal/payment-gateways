<?php

use Arca\PaymentGateways\Http\Controllers\PaypalController;
use Illuminate\Routing\Middleware\SubstituteBindings;

Route::middleware(SubstituteBindings::class)->group(function () {
    Route::get('paypal/init/{payment:uuid}', [PaypalController::class, 'init'])->name('paypal.init');
    Route::post('paypal/create/{payment:uuid}', [PaypalController::class, 'create'])->name('paypal.create');
    Route::post('paypal/capture/{payment:uuid}/{OrderID?}', [PaypalController::class, 'capture'])->name('paypal.capture');

    Route::get('paypal/successful/{payment:uuid}', [PaypalController::class, 'successful'])->name('paypal.successful');
    Route::get('paypal/rejected/{payment:uuid}', [PaypalController::class, 'rejected'])->name('paypal.rejected');
});
