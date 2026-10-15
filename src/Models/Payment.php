<?php

namespace Arca\PaymentGateways\Models;

use Arca\PaymentGateways\Casts\AsCustomer;
use Arca\PaymentGateways\Database\Factories\PaymentFactory;
use Arca\PaymentGateways\Enums\Currency;
use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\ValueObjects\Customer;
use Arca\PaymentGateways\ValueObjects\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property string $gateway
 * @property PaymentStatus $status
 * @property int $amount
 * @property Currency $currency
 * @property string|null $external_id Gateway checkout id: Webpay token, preference, order or session
 * @property string|null $transaction_id Gateway payment id once the buyer paid (used for refunds)
 * @property string|null $authorization_code
 * @property string|null $description
 * @property int $refunded_amount
 * @property string|null $failure_message
 * @property Customer|null $customer
 * @property array<string, mixed>|null $receipt Normalized receipt details, see receipt()
 * @property array<string, mixed>|null $metadata Your application data; the package never writes it
 * @property Carbon|null $paid_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'uuid',
        'gateway',
        'status',
        'amount',
        'currency',
        'external_id',
        'transaction_id',
        'authorization_code',
        'description',
        'failure_message',
        'refunded_amount',
        'customer',
        'receipt',
        'metadata',
        'paid_at',
        'expires_at',
    ];

    protected $attributes = [
        'status' => 'pending',
        'currency' => 'CLP',
        'refunded_amount' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'currency' => Currency::class,
            'amount' => 'integer',
            'refunded_amount' => 'integer',
            'customer' => AsCustomer::class,
            'receipt' => 'array',
            'metadata' => 'array',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * The uuid is filled on insert without model events, so it is still set under Event::fake().
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected static function newFactory(): PaymentFactory
    {
        return PaymentFactory::new();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function money(): Money
    {
        return new Money($this->amount, $this->currency);
    }

    /**
     * Every raw exchange with the gateway, oldest first.
     *
     * @return HasMany<PaymentLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(PaymentLog::class)->orderBy('id');
    }

    public function refundedMoney(): Money
    {
        return new Money($this->refunded_amount, $this->currency);
    }

    /**
     * The URL that starts (or resumes) the checkout. Send the buyer here.
     */
    public function checkoutUrl(): string
    {
        return route('payments.show', $this);
    }

    /**
     * Why the gateway rejected the payment, when it said so. Cleared once the payment is paid.
     */
    public function failureMessage(): ?string
    {
        return $this->failure_message;
    }

    /**
     * A receipt detail normalized across gateways. Keys, when the gateway reports them:
     * card_last_four, card_number (masked), card_brand, payment_method, payment_type,
     * payment_type_code, installments, installment_amount, transaction_date, accounting_date,
     * receipt_number, status_detail.
     */
    public function receipt(string $key, mixed $default = null): mixed
    {
        return $this->receipt[$key] ?? $default;
    }

    public function isPending(): bool
    {
        return $this->status === PaymentStatus::Pending;
    }

    public function isPaid(): bool
    {
        return $this->status === PaymentStatus::Paid;
    }

    public function isFailed(): bool
    {
        return $this->status === PaymentStatus::Failed;
    }

    public function isRefunded(): bool
    {
        return $this->status === PaymentStatus::Refunded;
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
