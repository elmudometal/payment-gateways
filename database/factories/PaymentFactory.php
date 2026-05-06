<?php

namespace Arca\PaymentGateways\Database\Factories;

use Arca\PaymentGateways\Enums\Currency;
use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'uuid' => fake()->unique()->uuid(),
            'gateway' => fake()->randomElement(['webpay', 'getnet', 'paypal', 'flow', 'niubiz']),
            'status' => PaymentStatus::Pending,
            'amount' => fake()->numberBetween(1000, 500000),
            'currency' => Currency::CLP,
            'description' => fake()->sentence(),
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Paid,
            'authorization_code' => fake()->uuid(),
            'paid_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Failed,
        ]);
    }

    public function gateway(string $gateway): static
    {
        return $this->state(fn () => ['gateway' => $gateway]);
    }
}
