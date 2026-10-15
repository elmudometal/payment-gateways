<?php

use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\Facades\PaymentGateways;
use Arca\PaymentGateways\Models\Payment;
use Illuminate\Support\Facades\File;

it('syncs pending payments that waited long enough', function () {
    $fake = PaymentGateways::fake('webpay');

    $old = Payment::factory()->gateway('webpay')->create(['external_id' => 'tok-old', 'created_at' => now()->subHour()]);
    $recent = Payment::factory()->gateway('webpay')->create(['external_id' => 'tok-new', 'created_at' => now()]);
    $neverStarted = Payment::factory()->gateway('webpay')->create(['created_at' => now()->subHour()]);
    $ancient = Payment::factory()->gateway('webpay')->create(['external_id' => 'tok-ancient', 'created_at' => now()->subWeek()]);

    $this->artisan('payment-gateways:sync')
        ->expectsOutputToContain("{$old->uuid} [webpay] pending -> paid")
        ->assertSuccessful();

    $fake->assertQueried(1);
    expect($old->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($recent->fresh()->isPending())->toBeTrue()
        ->and($neverStarted->fresh()->isPending())->toBeTrue()
        ->and($ancient->fresh()->isPending())->toBeTrue();
});

it('syncs a single payment by uuid', function () {
    PaymentGateways::fake('webpay');
    $payment = Payment::factory()->gateway('webpay')->create(['external_id' => 'tok']);

    $this->artisan('payment-gateways:sync', ['payment' => $payment->uuid])->assertSuccessful();

    expect($payment->fresh()->isPaid())->toBeTrue();
});

it('scaffolds a driver', function () {
    $dir = app_path('PaymentDrivers/Khipu');
    File::deleteDirectory($dir);

    $this->artisan('make:payment-driver', ['name' => 'Khipu'])->assertSuccessful();

    $code = File::get("{$dir}/KhipuDriver.php");
    File::deleteDirectory(app_path('PaymentDrivers'));

    expect($code)->toContain('namespace App\PaymentDrivers\Khipu;')
        ->toContain('class KhipuDriver extends AbstractDriver')
        ->toContain("return 'khipu';")
        ->toContain('public function complete(Payment $payment, Request $request): ChargeResult');
});
