<?php

namespace Arca\PaymentGateways\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One raw exchange with a gateway, kept untouched for audits.
 *
 * - direction "out": a call to the gateway API (request sent, response received).
 * - direction "in": the gateway reaching us: the buyer coming back ("return") or a notification
 *   ("webhook", with `verified` telling whether its signature checked out).
 *
 * `type` is the operation it belongs to: charge, return, complete, query, webhook or refund.
 * Secrets (keys, tokens, signatures) are masked; see payment-gateways.logs.redact.
 *
 * @property int $id
 * @property int|null $payment_id
 * @property string $gateway
 * @property string $type
 * @property string $direction
 * @property string|null $method
 * @property string|null $url
 * @property int|null $status_code
 * @property bool|null $verified
 * @property string|null $ip
 * @property mixed $request
 * @property mixed $response
 * @property array<string, string>|null $response_headers
 * @property string|null $error
 * @property Carbon $created_at
 */
class PaymentLog extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    public const TYPE_CHARGE = 'charge';

    public const TYPE_RETURN = 'return';

    public const TYPE_COMPLETE = 'complete';

    public const TYPE_QUERY = 'query';

    public const TYPE_WEBHOOK = 'webhook';

    public const TYPE_REFUND = 'refund';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'request' => 'json',
            'response' => 'json',
            'response_headers' => 'array',
            'verified' => 'boolean',
            'status_code' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Logs older than payment-gateways.logs.retention_days, for `php artisan model:prune`.
     * Nothing is pruned while it is null.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $days = config('payment-gateways.logs.retention_days');

        return $days === null || $days === ''
            ? static::query()->whereRaw('1 = 0')
            : static::query()->where('created_at', '<', now()->subDays((int) $days));
    }
}
