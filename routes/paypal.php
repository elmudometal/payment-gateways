<?php

use Arca\PaymentGateways\Http\Controllers\PaypalController;

Route::get('paypal/init', [PaypalController::class, 'init'])->name('paypal.init');
Route::post('paypal/create/{id}', [PaypalController::class, 'create'])->name('paypal.create');
Route::post('paypal/capture/{id}/{OrderID?}', [PaypalController::class, 'capture'])->name('paypal.capture');

Route::get('paypal/exito', [PaypalController::class, 'exito'])->name('paypal.exito');
Route::get('paypal/rechazo', [PaypalController::class, 'rechazo'])->name('paypal.rechazo');
