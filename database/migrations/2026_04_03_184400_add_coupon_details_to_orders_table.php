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
            $table->string('coupon_code')->nullable()->after('original_subtotal');
            $table->string('coupon_type')->nullable()->after('coupon_code'); // fixed or percentage
            $table->decimal('coupon_value', 15, 2)->nullable()->after('coupon_type'); // the raw percentage or fixed value
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['coupon_code', 'coupon_type', 'coupon_value']);
        });
    }
};
