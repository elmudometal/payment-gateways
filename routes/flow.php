<?php

use Illuminate\Routing\Middleware\SubstituteBindings;

Route::middleware(SubstituteBindings::class)
    ->prefix('flow')
    ->group(function () {
        $controller = config('payment-gateways.flow.controller');
        Route::get('init/{payment:uuid}', [$controller, 'init'])->name('flow.init');
        Route::get('commit/{payment:uuid}', [$controller, 'commit'])->name('flow.commit');
        Route::post('confirm/{payment:uuid}', [$controller, 'confirm'])->name('flow.confirm');
        Route::get('successful/{payment:uuid}', [$controller, 'successful'])->name('flow.successful');
        Route::get('rejected/{payment:uuid}', [$controller, 'rejected'])->name('flow.rejected');
    });
