<?php

namespace Arca\PaymentGateways\Support;

use Arca\PaymentGateways\Contracts\PaymentGateway;
use Arca\PaymentGateways\Contracts\RecordsExchanges;
use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\Models\PaymentLog;
use Illuminate\Http\Request;
use Throwable;

/**
 * Writes payment_logs. A log that cannot be written is reported but never breaks a payment.
 */
class PaymentLogger
{
    /**
     * Headers never stored: they carry the buyer's session or credentials.
     */
    protected const array PRIVATE_HEADERS = ['cookie', 'authorization', 'php-auth-user', 'php-auth-pw'];

    /**
     * Stores the driver's API calls made for one operation. Returns the ids of the logs written.
     *
     * @return list<int>
     */
    public function exchanges(?Payment $payment, string $gateway, string $type, PaymentGateway $driver): array
    {
        if (! $driver instanceof RecordsExchanges) {
            return [];
        }

        $ids = [];

        foreach ($driver->pullExchanges() as $exchange) {
            $log = $this->write([
                'payment_id' => $payment?->getKey(),
                'gateway' => $gateway,
                'type' => $type,
                'direction' => 'out',
            ] + $exchange);

            if ($log !== null) {
                $ids[] = (int) $log->getKey();
            }
        }

        return $ids;
    }

    /**
     * Stores a request the gateway sent us: the buyer coming back or a notification.
     */
    public function incoming(?Payment $payment, string $gateway, string $type, Request $request, ?bool $verified = null): ?PaymentLog
    {
        $headers = array_diff_key(
            array_map(fn (array $values) => implode(', ', $values), $request->headers->all()),
            array_flip(self::PRIVATE_HEADERS),
        );

        return $this->write([
            'payment_id' => $payment?->getKey(),
            'gateway' => $gateway,
            'type' => $type,
            'direction' => 'in',
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'verified' => $verified,
            'ip' => $request->ip(),
            'request' => [
                'headers' => $headers,
                'query' => $request->query(),
                'body' => $this->body($request),
            ],
        ]);
    }

    /**
     * Links logs written before the payment was known (a notification) to it.
     */
    public function attach(Payment $payment, int|string|null ...$logIds): void
    {
        $logIds = array_filter($logIds, fn ($id) => $id !== null);

        if ($logIds === []) {
            return;
        }

        try {
            PaymentLog::query()->whereKey($logIds)->update(['payment_id' => $payment->getKey()]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The body as received: decoded JSON, form fields, or the raw text.
     */
    protected function body(Request $request): mixed
    {
        $content = $request->getContent();

        if ($content === '') {
            return $request->request->all() ?: null;
        }

        if (json_validate($content)) {
            return json_decode($content, true);
        }

        return $request->request->all() ?: $content;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function write(array $attributes): ?PaymentLog
    {
        if (! config('payment-gateways.logs.enabled', true)) {
            return null;
        }

        try {
            $attributes['request'] = Journal::redact($attributes['request'] ?? null);
            $attributes['url'] = isset($attributes['url']) ? Journal::redactUrl((string) $attributes['url']) : null;

            $log = new PaymentLog;
            $log->forceFill($attributes)->save();

            return $log;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
