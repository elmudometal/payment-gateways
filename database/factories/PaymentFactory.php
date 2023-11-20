<?php

namespace Arca\PaymentGateways\Database\Factories;

use Arca\PaymentGateways\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Model>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => fake()->unique()->uuid,
            'amount' => fake()->randomNumber('5'),
            'model_id' => fake()->randomNumber('3'),
            'model_type' => fake()->randomElement(['Paypal', 'webpay', 'Getnet']),
            'status' => fake()->randomElement([Payment::ESTATUS_PENDIENTE, Payment::ESTATUS_CANCELADA]),
            'voucher' => fake()->sentence(),
            'comments' => fake()->paragraph(),
        ];
    }
}
