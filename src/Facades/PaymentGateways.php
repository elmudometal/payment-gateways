<?php

namespace Arca\PaymentGateways\Facades;

use Arca\PaymentGateways\Services\PaymentService;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Arca\PaymentGateways\Models\Payment create(string $gateway, \Arca\PaymentGateways\ValueObjects\Money $money, ?string $description = null, ?\Illuminate\Database\Eloquent\Model $payable = null, ?\Arca\PaymentGateways\ValueObjects\Customer $customer = null, array $metadata = [], ?\DateTimeInterface $expiresAt = null)
 * @method static \Arca\PaymentGateways\ValueObjects\ChargeResult charge(\Arca\PaymentGateways\Models\Payment $payment)
 * @method static \Arca\PaymentGateways\ValueObjects\ChargeResult complete(\Arca\PaymentGateways\Models\Payment $payment, \Illuminate\Http\Request $request)
 * @method static \Arca\PaymentGateways\ValueObjects\ChargeResult sync(\Arca\PaymentGateways\Models\Payment $payment)
 * @method static \Arca\PaymentGateways\ValueObjects\RefundResult refund(\Arca\PaymentGateways\Models\Payment $payment, ?\Arca\PaymentGateways\ValueObjects\Money $amount = null)
 * @method static \Arca\PaymentGateways\Models\Payment|null handleWebhook(string $gateway, \Illuminate\Http\Request $request)
 * @method static \Arca\PaymentGateways\Contracts\PaymentGateway driver(?string $gateway = null)
 * @method static PaymentService extend(string $gateway, \Closure $callback)
 * @method static \Arca\PaymentGateways\Support\Testing\FakeDriver fake(?string $gateway = null)
 *
 * @see PaymentService
 */
class PaymentGateways extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PaymentService::class;
    }
}
