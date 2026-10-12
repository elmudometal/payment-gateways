<?php

use Arca\PaymentGateways\Models\Payment;
use Illuminate\Support\Facades\Schema;

it('creates the payments table on databases without a UUID() function', function () {
    // The test database is SQLite, which has no UUID(); the previous default failed here.
    $migration = include __DIR__.'/../database/migrations/create_payment_gateways_table.php.stub';
    $migration->up();

    $payment = Payment::factory()->create([
        'type' => 'transfer',
        'amount' => 5000,
        'model_type' => Payment::class,
        'model_id' => 1,
    ]);

    expect(Schema::hasTable('payments'))->toBeTrue()
        ->and($payment->fresh()->uuid)->toMatch('/^[0-9a-f-]{36}$/');
});
