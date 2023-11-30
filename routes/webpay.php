<?php

use Illuminate\Routing\Middleware\SubstituteBindings;

Route::middleware(SubstituteBindings::class)->group(function () {
    $controller = config('payment-gateways.webpay.controller');
    Route::get('webpay/init/{payment:uuid}', [$controller, 'init'])->name('webpay.init');
    Route::get('webpay/successful/{payment:uuid}', [$controller, 'successful'])->name('webpay.successful');
    Route::get('webpay/rejected/{payment:uuid}', [$controller, 'rejected'])->name('webpay.rejected');
    Route::match(['get', 'post'], 'webpay/commit/{payment:uuid}', [$controller, 'commit'])->name('webpay.commit');
});
