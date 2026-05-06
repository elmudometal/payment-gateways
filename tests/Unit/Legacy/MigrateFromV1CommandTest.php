<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    // Drop v2 table and create v1 schema
    Schema::dropIfExists('payments');
    Schema::dropIfExists('payments_v1_backup');

    Schema::create('payments', function ($table) {
        $table->id();
        $table->uuid('uuid')->nullable()->unique();
        $table->string('type');
        $table->string('token')->nullable();
        $table->string('authorization_code')->nullable();
        $table->string('status');
        $table->integer('amount');
        $table->text('comments')->nullable();
        $table->text('voucher')->nullable();
        $table->unsignedBigInteger('model_id');
        $table->string('model_type');
        $table->softDeletes();
        $table->timestamps();
    });
});

it('migrates v1 schema to v2', function () {
    DB::table('payments')->insert([
        'uuid' => 'abc-123',
        'type' => 'Webpay',
        'token' => 'tok-456',
        'authorization_code' => 'AUTH-789',
        'status' => 'Paid',
        'amount' => 50000,
        'comments' => 'Test payment',
        'voucher' => json_encode(['response' => 'ok']),
        'model_id' => 1,
        'model_type' => 'App\\Models\\Order',
        'created_at' => now()->subDay(),
        'updated_at' => now(),
    ]);

    $this->artisan('payment-gateways:migrate-from-v1')
        ->assertSuccessful();

    // Verify column renames
    expect(Schema::hasColumn('payments', 'gateway'))->toBeTrue()
        ->and(Schema::hasColumn('payments', 'type'))->toBeFalse()
        ->and(Schema::hasColumn('payments', 'external_id'))->toBeTrue()
        ->and(Schema::hasColumn('payments', 'token'))->toBeFalse()
        ->and(Schema::hasColumn('payments', 'description'))->toBeTrue()
        ->and(Schema::hasColumn('payments', 'comments'))->toBeFalse()
        ->and(Schema::hasColumn('payments', 'metadata'))->toBeTrue()
        ->and(Schema::hasColumn('payments', 'voucher'))->toBeFalse()
        ->and(Schema::hasColumn('payments', 'payable_id'))->toBeTrue()
        ->and(Schema::hasColumn('payments', 'model_id'))->toBeFalse();

    // Verify new columns
    expect(Schema::hasColumn('payments', 'currency'))->toBeTrue()
        ->and(Schema::hasColumn('payments', 'paid_at'))->toBeTrue()
        ->and(Schema::hasColumn('payments', 'expires_at'))->toBeTrue();

    // Verify data migration
    $payment = DB::table('payments')->where('uuid', 'abc-123')->first();

    expect($payment->gateway)->toBe('webpay')
        ->and($payment->status)->toBe('paid')
        ->and($payment->external_id)->toBe('tok-456')
        ->and($payment->description)->toBe('Test payment')
        ->and($payment->currency)->toBe('CLP')
        ->and($payment->paid_at)->not->toBeNull();

    // Verify backup was created
    expect(Schema::hasTable('payments_v1_backup'))->toBeTrue()
        ->and(DB::table('payments_v1_backup')->count())->toBe(1);
});

it('maps all v1 status values correctly', function () {
    $statuses = [
        'Paid' => 'paid',
        'Pending' => 'pending',
        'Canceled' => 'failed',
        'Reversed' => 'reversed',
    ];

    foreach ($statuses as $v1Status => $expected) {
        DB::table('payments')->insert([
            'uuid' => "uuid-{$v1Status}",
            'type' => 'webpay',
            'status' => $v1Status,
            'amount' => 1000,
            'model_id' => 1,
            'model_type' => 'App\\Models\\Order',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $this->artisan('payment-gateways:migrate-from-v1', ['--no-backup' => true])
        ->assertSuccessful();

    foreach ($statuses as $v1Status => $expected) {
        $payment = DB::table('payments')->where('uuid', "uuid-{$v1Status}")->first();
        expect($payment->status)->toBe($expected, "Status mapping failed for {$v1Status}");
    }
});

it('normalizes gateway names to lowercase', function () {
    $gateways = ['Webpay', 'Getnet', 'PayPal', 'Paypal', 'Flow', 'Niubiz'];

    foreach ($gateways as $gw) {
        DB::table('payments')->insert([
            'uuid' => "uuid-{$gw}",
            'type' => $gw,
            'status' => 'Pending',
            'amount' => 1000,
            'model_id' => 1,
            'model_type' => 'App\\Models\\Order',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $this->artisan('payment-gateways:migrate-from-v1', ['--no-backup' => true])
        ->assertSuccessful();

    foreach ($gateways as $gw) {
        $payment = DB::table('payments')->where('uuid', "uuid-{$gw}")->first();
        expect($payment->gateway)->toBe(strtolower($gw === 'PayPal' ? 'paypal' : $gw));
    }
});

it('runs in dry-run mode without modifying anything', function () {
    DB::table('payments')->insert([
        'uuid' => 'dry-run-uuid',
        'type' => 'Webpay',
        'status' => 'Paid',
        'amount' => 1000,
        'model_id' => 1,
        'model_type' => 'App\\Models\\Order',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('payment-gateways:migrate-from-v1', ['--dry-run' => true])
        ->assertSuccessful();

    // Schema should NOT have changed
    expect(Schema::hasColumn('payments', 'type'))->toBeTrue()
        ->and(Schema::hasColumn('payments', 'gateway'))->toBeFalse();
});

it('skips if already migrated', function () {
    // Migrate once
    $this->artisan('payment-gateways:migrate-from-v1', ['--no-backup' => true])
        ->assertSuccessful();

    // Try again
    $this->artisan('payment-gateways:migrate-from-v1')
        ->assertSuccessful()
        ->expectsOutput('The payments table appears to already have v2.0 columns. Skipping.');
});

it('fails if payments table does not exist', function () {
    Schema::dropIfExists('payments');

    $this->artisan('payment-gateways:migrate-from-v1')
        ->assertFailed();
});
