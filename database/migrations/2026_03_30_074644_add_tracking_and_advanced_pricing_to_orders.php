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
            $table->string('courier_name')->nullable()->after('shipping_method');
            $table->string('tracking_number')->nullable()->after('courier_name');
            $table->string('tracking_url')->text()->nullable()->after('tracking_number');
            $table->timestamp('shipped_at')->nullable()->after('tracking_url');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('regular_price', 15, 2)->nullable()->after('variant_id');
            $table->decimal('discount_price', 15, 2)->default(0.00)->after('regular_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['courier_name', 'tracking_number', 'tracking_url', 'shipped_at']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['regular_price', 'discount_price']);
        });
    }
};
