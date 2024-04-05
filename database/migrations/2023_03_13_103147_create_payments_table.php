<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->unique()->default(DB::raw('(UUID())'));
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
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('pagos');
    }
};
