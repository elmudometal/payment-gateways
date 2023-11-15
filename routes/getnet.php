<?php

use Arca\PaymentGateways\Http\Controllers\GetnetController;
use Illuminate\Support\Facades\Route;

Route::get('getnet/init/{id}', [GetnetController::class, 'init'])->name('getnet.init');
Route::get('getnet/exito/{id}', [GetnetController::class, 'exito'])->name('getnet.exito');
Route::get('getnet/rechazo/{id}', [GetnetController::class, 'rechazo'])->name('getnet.rechazo');
Route::match(['get', 'post'], 'getnet/commit/{id}', [GetnetController::class, 'commit'])->name('getnet.commit');
