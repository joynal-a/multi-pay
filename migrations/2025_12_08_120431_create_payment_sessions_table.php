<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('session_id')->unique();
            $table->string('gateway');
            $table->string('order_id')->index();
            $table->string('payment_id')->nullable()->index();
            $table->longText('payment_url')->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('currency', 10);
            $table->json('customer')->nullable();
            $table->json('meta')->nullable();
            $table->string('status')->default('pending');
            $table->json('payload')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_sessions');
    }
};
