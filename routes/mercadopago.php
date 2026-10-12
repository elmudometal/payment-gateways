<?php

use Illuminate\Routing\Middleware\SubstituteBindings;

Route::middleware(SubstituteBindings::class)
    ->prefix('mercadopago')
    ->group(function () {
        $controller = config('payment-gateways.mercadopago.controller');
        Route::get('init/{payment:uuid}', [$controller, 'init'])->name('mercadopago.init');
        Route::get('commit/{payment:uuid}', [$controller, 'commit'])->name('mercadopago.commit');
        Route::post('notification/{payment:uuid}', [$controller, 'notification'])->name('mercadopago.notification');
        Route::get('successful/{payment:uuid}', [$controller, 'successful'])->name('mercadopago.successful');
        Route::get('pending/{payment:uuid}', [$controller, 'pending'])->name('mercadopago.pending');
        Route::get('rejected/{payment:uuid}', [$controller, 'rejected'])->name('mercadopago.rejected');
    });
