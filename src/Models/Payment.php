<?php

namespace Arca\PaymentGateways\Models;

use Arca\PaymentGateways\Events\PaymentApproved;
use Arca\PaymentGateways\Events\PaymentRejected;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * Arca\PaymentGateways\Models\Payment
 *
 * @property string $uuid
 * @property string $token
 * @property string $authorization_code
 * @property string $status
 * @property float|int $amount
 * @property string $comments
 * @property array $voucher
 */
class Payment extends Model
{
    use HasFactory;

    const PAID_STATUS = 'Paid';

    const PENDING_STATUS = 'Pending';

    const CANCELED_STATUS = 'Canceled';

    const REVERSED_STATUS = 'Reversed';

    protected $fillable = [
        'uuid',
        'type',
        'token',
        'authorization_code',
        'status',
        'amount',
        'comments',
        'voucher',
    ];

    protected $attributes = [
        'status' => self::PENDING_STATUS,
    ];

    protected $casts = [
        'voucher' => 'json',
    ];

    protected static function booted(): void
    {
        static::updated(function (Payment $payment) {
            if ($payment->wasChanged('status')) {
                if ($payment->status == Payment::PAID_STATUS) {
                    PaymentApproved::dispatch($payment);
                }

                if ($payment->status == Payment::CANCELED_STATUS) {
                    PaymentRejected::dispatch($payment);
                }
            }
        });
    }

    protected static function newFactory()
    {
        $package = Str::before(get_called_class(), 'Models\\');
        $modelName = Str::after(get_called_class(), 'Models\\');
        $path = $package.'Database\\Factories\\'.$modelName.'Factory';

        return $path::new();
    }

    public function model(): MorphTo
    {
        return $this->morphTo();
    }
}
