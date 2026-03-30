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
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_method_name')->nullable()->after('payment_method');
            $table->string('payment_gateway')->nullable()->after('payment_method_name');
            $table->string('transaction_id')->nullable()->after('payment_gateway');
            $table->json('payment_details')->nullable()->after('transaction_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'payment_method_name',
                'payment_gateway',
                'transaction_id',
                'payment_details',
            ]);
        });
    }
};
