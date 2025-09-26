<?php

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

Route::middleware(SubstituteBindings::class)->group(function () {
    $controller = config('payment-gateways.getnet.controller');
    Route::get('getnet/init/{payment:uuid}', [$controller, 'init'])->name('getnet.init');
    Route::get('getnet/successful/{payment:uuid}', [$controller, 'successful'])->name('getnet.successful');
    Route::get('getnet/rejected/{payment:uuid}', [$controller, 'rejected'])->name('getnet.rejected');
    Route::match(['get', 'post'], 'getnet/commit/{payment:uuid}', [$controller, 'commit'])->name('getnet.commit');
    Route::match(['get', 'post'],'getnet/notification', [$controller, 'notification'])->name('getnet.notification');
});
