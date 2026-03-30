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
            $table->string('shipping_first_name')->nullable()->after('shipping_address_id');
            $table->string('shipping_last_name')->nullable()->after('shipping_first_name');
            $table->string('shipping_email')->nullable()->after('shipping_last_name');
            $table->string('shipping_phone')->nullable()->after('shipping_email');
            $table->string('shipping_country')->nullable()->after('shipping_phone');
            $table->string('shipping_street_address')->nullable()->after('shipping_country');
            $table->string('shipping_city')->nullable()->after('shipping_street_address');
            $table->string('shipping_state')->nullable()->after('shipping_city');
            $table->string('shipping_zip_code')->nullable()->after('shipping_state');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'shipping_first_name',
                'shipping_last_name',
                'shipping_email',
                'shipping_phone',
                'shipping_country',
                'shipping_street_address',
                'shipping_city',
                'shipping_state',
                'shipping_zip_code',
            ]);
        });
    }
};
