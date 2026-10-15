<?php

namespace Arca\PaymentGateways\Models;

use Arca\PaymentGateways\Database\Factories\PaymentFactory;
use Arca\PaymentGateways\Enums\Currency;
use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\ValueObjects\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $uuid
 * @property string $gateway
 * @property PaymentStatus $status
 * @property int $amount
 * @property Currency $currency
 * @property string|null $external_id
 * @property string|null $authorization_code
 * @property string|null $description
 * @property array $metadata
 * @property \DateTimeInterface|null $paid_at
 * @property \DateTimeInterface|null $expires_at
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
            'metadata' => 'json',
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

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
