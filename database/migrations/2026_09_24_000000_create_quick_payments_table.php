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
        Schema::create('quick_payments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->text('reason');
            $table->decimal('amount', 12, 2)->default(500.00);
            $table->string('currency', 10)->default('USD');
            $table->string('status', 30)->default('completed');
            $table->string('payment_method', 50)->default('card');
            $table->string('transaction_id', 100)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quick_payments');
    }
};
