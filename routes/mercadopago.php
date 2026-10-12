<?php

use Illuminate\Routing\Middleware\SubstituteBindings;

Route::prefix('mercadopago')
    ->group(function () {
        $controller = config('payment-gateways.mercadopago.controller');

        // Pages the buyer's browser visits: they get the "web" group, so applications can use the
        // session (flash messages, authenticated user) from the after* hooks.
        Route::middleware('web')->group(function () use ($controller) {
            Route::get('init/{payment:uuid}', [$controller, 'init'])->name('mercadopago.init');
            Route::get('commit/{payment:uuid}', [$controller, 'commit'])->name('mercadopago.commit');
            Route::get('successful/{payment:uuid}', [$controller, 'successful'])->name('mercadopago.successful');
            Route::get('pending/{payment:uuid}', [$controller, 'pending'])->name('mercadopago.pending');
            Route::get('rejected/{payment:uuid}', [$controller, 'rejected'])->name('mercadopago.rejected');
        });

        // Server-to-server webhook: no session and no CSRF check.
        Route::middleware(SubstituteBindings::class)
            ->post('notification/{payment:uuid}', [$controller, 'notification'])
            ->name('mercadopago.notification');
    });
