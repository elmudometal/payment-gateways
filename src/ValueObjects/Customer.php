<?php

namespace Arca\PaymentGateways\ValueObjects;

final readonly class Customer
{
    public function __construct(
        public ?string $name = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $ip = null,
    ) {}

    public static function fromRequest(): self
    {
        return new self(ip: request()->ip());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? null,
            email: $data['email'] ?? null,
            phone: $data['phone'] ?? null,
            ip: $data['ip'] ?? null,
        );
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'ip' => $this->ip,
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }
}
