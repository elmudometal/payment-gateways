<?php

namespace Arca\PaymentGateways\Support\Legacy;

use Arca\PaymentGateways\Models\Payment;
use Illuminate\Support\Facades\Route;

/**
 * Registers redirect routes from v1 route names to v2.
 *
 * Call in your AppServiceProvider::boot() if you need old routes
 * to keep working during migration:
 *
 *   LegacyRouteRedirector::register();
 */
class LegacyRouteRedirector
{
    protected const array GATEWAYS = ['webpay', 'getnet', 'paypal', 'flow', 'niubiz'];

    public static function register(): void
    {
        foreach (self::GATEWAYS as $gateway) {
            Route::get("{$gateway}/init/{payment:uuid}", function (Payment $payment) {
                return redirect()->route('payments.show', $payment, 301);
            })->name("{$gateway}.init");

            Route::match(['get', 'post'], "{$gateway}/commit/{payment:uuid}", function (Payment $payment) {
                return redirect()->route('payments.commit', $payment, 307);
            })->name("{$gateway}.commit");

            Route::get("{$gateway}/successful/{payment:uuid}", function (Payment $payment) {
                return redirect()->route('payments.success', $payment, 301);
            })->name("{$gateway}.successful");

            Route::get("{$gateway}/rejected/{payment:uuid}", function (Payment $payment) {
                return redirect()->route('payments.failed', $payment, 301);
            })->name("{$gateway}.rejected");
        }
    }
}
