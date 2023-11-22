<?php

use Arca\PaymentGateways\Http\Controllers\GetnetController;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

Route::middleware(SubstituteBindings::class)->group(function () {
    Route::get('getnet/init/{payment:uuid}', [GetnetController::class, 'init'])->name('getnet.init');
    Route::get('getnet/successful/{payment:uuid}', [GetnetController::class, 'successful'])->name('getnet.successful');
    Route::get('getnet/rejected/{payment:uuid}', [GetnetController::class, 'rejected'])->name('getnet.rejected');
    Route::match(['get', 'post'], 'getnet/commit/{payment:uuid}', [GetnetController::class, 'commit'])->name('getnet.commit');
});
