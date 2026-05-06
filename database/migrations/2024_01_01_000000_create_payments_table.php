<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('gateway');
            $table->string('status')->default('pending')->index();
            $table->bigInteger('amount');
            $table->string('currency', 3)->default('CLP');
            $table->string('external_id')->nullable()->index();
            $table->string('authorization_code')->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->nullableMorphs('payable');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
