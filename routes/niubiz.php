<?php

use Illuminate\Routing\Middleware\SubstituteBindings;

Route::middleware(SubstituteBindings::class)
    ->prefix('niubiz')
    ->group(function () {
        $controller = config('payment-gateways.niubiz.controller');
        Route::get('init/{payment:uuid}', [$controller, 'init'])->name('niubiz.init');
        Route::post('authorize/{payment:uuid}', [$controller, 'authorize'])->name('niubiz.authorize');
        Route::get('successful/{payment:uuid}', [$controller, 'successful'])->name('niubiz.successful');
        Route::get('rejected/{payment:uuid}', [$controller, 'rejected'])->name('niubiz.rejected');
    });
