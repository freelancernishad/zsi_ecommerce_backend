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
            $table->string('payment_status')->default('unpaid')->after('total'); // paid, unpaid, refunded
            $table->string('fulfillment_status')->default('unfulfilled')->after('payment_status'); // fulfilled, unfulfilled
            $table->string('delivery_status')->default('pending')->after('fulfillment_status'); // pending, processing, shipped, delivered, cancelled, return_requested, returned
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['payment_status', 'fulfillment_status', 'delivery_status']);
        });
    }
};
