<?php

use Arca\PaymentGateways\Events\PaymentApproved;
use Arca\PaymentGateways\Events\PaymentRejected;
use Arca\PaymentGateways\Models\Payment;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('payments', function (Blueprint $table) {
        $table->id();
        $table->uuid('uuid')->nullable()->unique();
        $table->string('type');
        $table->string('token')->nullable();
        $table->string('authorization_code')->nullable();
        $table->string('status');
        $table->integer('amount');
        $table->text('comments')->nullable();
        $table->text('voucher')->nullable();
        $table->morphs('model');
        $table->softDeletes();
        $table->timestamps();
    });

    $this->payment = Payment::factory()->create([
        'type' => 'mercadopago',
        'status' => Payment::PENDING_STATUS,
        'amount' => 15000,
        'model_type' => Payment::class,
        'model_id' => 1,
    ]);
});

it('dispatches the approved event once when the status changes to paid', function () {
    Event::fake([PaymentApproved::class, PaymentRejected::class]);

    $this->payment->update(['status' => Payment::PAID_STATUS]);
    $this->payment->update(['comments' => 'sin cambio de estado']);

    Event::assertDispatchedTimes(PaymentApproved::class, 1);
    Event::assertNotDispatched(PaymentRejected::class);
});

it('dispatches the rejected event when the status changes to canceled', function () {
    Event::fake([PaymentApproved::class, PaymentRejected::class]);

    $this->payment->update(['status' => Payment::CANCELED_STATUS]);

    Event::assertDispatchedTimes(PaymentRejected::class, 1);
});

it('dispatches the event for a payment created already paid', function () {
    Event::fake([PaymentApproved::class]);

    Payment::factory()->create(['type' => 'transfer', 'status' => Payment::PAID_STATUS, 'amount' => 1, 'model_type' => Payment::class, 'model_id' => 2]);

    Event::assertDispatchedTimes(PaymentApproved::class, 1);
});

it('lets a listener save the same payment without dispatching the event again', function () {
    $dispatches = 0;

    Event::listen(PaymentApproved::class, function (PaymentApproved $event) use (&$dispatches) {
        $dispatches++;

        // Typical listener work: annotate the payment that was just approved.
        $event->payment->update(['comments' => 'Conciliado']);
    });

    $this->payment->update(['status' => Payment::PAID_STATUS]);

    expect($dispatches)->toBe(1)
        ->and($this->payment->fresh()->comments)->toBe('Conciliado');
});
