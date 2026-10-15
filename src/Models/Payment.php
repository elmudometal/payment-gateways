<?php

namespace Arca\PaymentGateways\Models;

use Arca\PaymentGateways\Database\Factories\PaymentFactory;
use Arca\PaymentGateways\Enums\Currency;
use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\ValueObjects\Customer;
use Arca\PaymentGateways\ValueObjects\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
 * @property array<string, mixed>|null $metadata
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
        'metadata',
        'paid_at',
        'expires_at',
    ];

    protected $attributes = [
        'status' => 'pending',
        'currency' => 'CLP',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'currency' => Currency::class,
            'amount' => 'integer',
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

    public function customer(): ?Customer
    {
        $customer = $this->metadata['customer'] ?? null;

        return is_array($customer) ? Customer::fromArray($customer) : null;
    }

    /**
     * The URL that starts (or resumes) the checkout. Send the buyer here.
     */
    public function checkoutUrl(): string
    {
        return route('payments.show', $this);
    }

    /**
     * The last gateway response stored for this payment.
     *
     * @return array<string, mixed>
     */
    public function voucher(): array
    {
        return $this->metadata['voucher'] ?? [];
    }

    /**
     * Why the gateway rejected the payment, when it said so.
     */
    public function failureMessage(): ?string
    {
        return $this->metadata['message'] ?? null;
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
