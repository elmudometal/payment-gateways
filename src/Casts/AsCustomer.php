<?php

namespace Arca\PaymentGateways\Casts;

use Arca\PaymentGateways\ValueObjects\Customer;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores a Customer as JSON.
 *
 * @implements CastsAttributes<Customer|null, Customer|array<string, mixed>|null>
 */
class AsCustomer implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Customer
    {
        $data = is_string($value) ? json_decode($value, true) : null;

        return is_array($data) && $data !== [] ? Customer::fromArray($data) : null;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        $data = match (true) {
            $value === null => [],
            $value instanceof Customer => $value->toArray(),
            default => Customer::fromArray($value)->toArray(),
        };

        return $data === [] ? null : (string) json_encode($data);
    }
}
