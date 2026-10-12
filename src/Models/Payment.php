<?php

namespace Arca\PaymentGateways\Models;

use Arca\PaymentGateways\Database\Factories\PaymentFactory;
use Arca\PaymentGateways\Events\PaymentApproved;
use Arca\PaymentGateways\Events\PaymentRejected;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
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
    use HasFactory, SoftDeletes;

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

    /**
     * Status event waiting for the save to finish (see finishSave()).
     */
    protected bool $statusEventPending = false;

    protected static function booted(): void
    {
        static::updated(function (Payment $payment) {
            if ($payment->wasChanged('status')) {
                $payment->statusEventPending = true;
            }
        });

        static::creating(function (Payment $payment) {
            $payment->uuid = (string) Str::uuid(); // Genera UUID al crear un pago
        });

        static::created(function (Payment $payment) {
            $payment->statusEventPending = true;
        });
    }

    /**
     * Dispatch PaymentApproved / PaymentRejected only once the save is complete.
     *
     * Eloquent fires "created" and "updated" before syncing the original attributes. Dispatching
     * there meant that a listener saving this same payment (to add a comment, for instance) still
     * saw the status as changed and dispatched the event again, looping forever.
     */
    protected function finishSave(array $options)
    {
        parent::finishSave($options);

        if (! $this->statusEventPending) {
            return;
        }

        $this->statusEventPending = false;

        match ($this->status) {
            self::PAID_STATUS => PaymentApproved::dispatch($this),
            self::CANCELED_STATUS => PaymentRejected::dispatch($this),
            default => null,
        };
    }

    protected static function newFactory(): PaymentFactory
    {
        return PaymentFactory::new();
    }

    public function model(): MorphTo
    {
        return $this->morphTo();
    }
}
