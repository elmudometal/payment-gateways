<?php

use Illuminate\Routing\Middleware\SubstituteBindings;

Route::middleware(SubstituteBindings::class)->group(function () {
    $controller = config('payment-gateways.paypal.controller');
    Route::get('paypal/init/{payment:uuid}', [$controller, 'init'])->name('paypal.init');
    Route::post('paypal/create/{payment:uuid}', [$controller, 'create'])->name('paypal.create');
    Route::post('paypal/commit/{payment:uuid}/{OrderID?}', [$controller, 'commit'])->name('paypal.commit');

    Route::get('paypal/successful/{payment:uuid}', [$controller, 'successful'])->name('paypal.successful');
    Route::get('paypal/rejected/{payment:uuid}', [$controller, 'rejected'])->name('paypal.rejected');
});
