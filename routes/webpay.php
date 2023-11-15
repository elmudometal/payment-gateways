<?php

use Arca\PaymentGateways\Http\Controllers\WebpayController;

Route::get('webpay/init/{id}', [WebpayController::class, 'init'])->name('webpay.init');
Route::get('webpay/exito/{id}', [WebpayController::class, 'exito'])->name('webpay.exito');
Route::get('webpay/rechazo/{id}', [WebpayController::class, 'rechazo'])->name('webpay.rechazo');
Route::match(['get', 'post'], 'webpay/commit/{id}', [WebpayController::class, 'commit'])->name('webpay.commit');
