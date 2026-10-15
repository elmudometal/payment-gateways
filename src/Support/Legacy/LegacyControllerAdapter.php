<?php

namespace Arca\PaymentGateways\Support\Legacy;

use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\Events\PaymentApproved;
use Arca\PaymentGateways\Events\PaymentRejected;
use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\Services\PaymentService;
use Arca\PaymentGateways\Support\PaymentManager;
use Arca\PaymentGateways\ValueObjects\ChargeIntent;
use Arca\PaymentGateways\ValueObjects\ChargeResult;
use Arca\PaymentGateways\ValueObjects\Money;
use Arca\PaymentGateways\ValueObjects\ReturnUrls;
use Illuminate\Routing\Controller;

/**
 * Bridge for v1 applications that extended gateway controllers.
 *
 * Provides the same hook methods (afterSuccessful, afterRejected, etc.)
 * while delegating to the v2 driver system underneath.
 *
 * Usage in your app:
 *
 *   class MyWebpayController extends LegacyControllerAdapter
 *   {
 *       protected string $gateway = 'webpay';
 *
 *       public function afterSuccessful(Payment $payment)
 *       {
 *           return view('my-custom-success', compact('payment'));
 *       }
 *   }
 *
 * Register in routes:
 *
 *   Route::get('webpay/init/{payment:uuid}', [MyWebpayController::class, 'init']);
 *   Route::match(['get','post'], 'webpay/commit/{payment:uuid}', [MyWebpayController::class, 'commit']);
 *   Route::get('webpay/successful/{payment:uuid}', [MyWebpayController::class, 'successful']);
 *   Route::get('webpay/rejected/{payment:uuid}', [MyWebpayController::class, 'rejected']);
 */
abstract class LegacyControllerAdapter extends Controller
{
    protected string $gateway;

    protected function manager(): PaymentManager
    {
        return app(PaymentManager::class);
    }

    protected function service(): PaymentService
    {
        return app(PaymentService::class);
    }

    public function init(Payment $payment)
    {
        if ($payment->isPaid()) {
            return $this->afterSuccessful($payment);
        }

        if ($payment->isFailed()) {
            return $this->afterRejected($payment);
        }

        $driver = $this->manager()->driver($this->gateway);

        $intent = new ChargeIntent(
            money: $payment->money(),
            description: $payment->description ?? '',
            urls: new ReturnUrls(
                returnUrl: $this->commitUrl($payment),
            ),
        );

        $result = $driver->charge($payment, $intent);

        if ($result->requiresRedirect()) {
            return redirect()->away($result->redirectUrl);
        }

        if ($result->isApproved()) {
            $this->applyResult($payment, $result);

            return $this->afterSuccessful($payment);
        }

        $this->applyResult($payment, $result);

        return $this->afterRejected($payment);
    }

    public function commit(Payment $payment)
    {
        $this->beforeCommit($payment);

        $result = $this->service()->query($payment);

        $this->applyResult($payment, $result);
        $this->afterCommit($payment);

        if ($result->isApproved()) {
            return redirect()->route("{$this->gateway}.successful", $payment->uuid);
        }

        return redirect()->route("{$this->gateway}.rejected", $payment->uuid);
    }

    public function successful(Payment $payment)
    {
        return $this->afterSuccessful($payment);
    }

    public function rejected(Payment $payment)
    {
        return $this->afterRejected($payment);
    }

    public function beforeCommit(Payment $payment): void {}

    public function afterCommit(Payment $payment): void {}

    public function afterSuccessful(Payment $payment)
    {
        return view('payment-gateways::success', compact('payment'));
    }

    public function afterRejected(Payment $payment, string $error = '')
    {
        return view('payment-gateways::failed', compact('payment', 'error'));
    }

    protected function commitUrl(Payment $payment): string
    {
        return route("{$this->gateway}.commit", $payment->uuid);
    }

    protected function applyResult(Payment $payment, ChargeResult $result): void
    {
        $previousStatus = $payment->status;

        $payment->status = $result->status;
        $payment->external_id = $result->externalId ?? $payment->external_id;
        $payment->authorization_code = $result->authorizationCode ?? $payment->authorization_code;

        if ($result->raw) {
            $payment->metadata = array_merge($payment->metadata ?? [], ['voucher' => $result->raw]);
        }

        if ($result->isApproved() && ! $payment->paid_at) {
            $payment->paid_at = now();
        }

        $payment->save();

        if ($previousStatus !== $payment->status) {
            match ($payment->status) {
                PaymentStatus::Paid => PaymentApproved::dispatch($payment),
                PaymentStatus::Failed => PaymentRejected::dispatch($payment),
                default => null,
            };
        }
    }
}
