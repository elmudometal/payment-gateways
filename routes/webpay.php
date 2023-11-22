<?php

use Arca\PaymentGateways\Http\Controllers\WebpayController;
use Illuminate\Routing\Middleware\SubstituteBindings;

Route::middleware(SubstituteBindings::class)->group(function () {
    Route::get('webpay/init/{payment:uuid}', [WebpayController::class, 'init'])->name('webpay.init');
    Route::get('webpay/successful/{payment:uuid}', [WebpayController::class, 'successful'])->name('webpay.successful');
    Route::get('webpay/rejected/{payment:uuid}', [WebpayController::class, 'rejected'])->name('webpay.rejected');
    Route::match(['get', 'post'], 'webpay/commit/{payment:uuid}', [WebpayController::class, 'commit'])->name('webpay.commit');
});
