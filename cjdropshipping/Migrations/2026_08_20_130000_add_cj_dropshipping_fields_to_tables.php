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
        if (!Schema::hasColumn('products', 'cj_product_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('cj_product_id')->nullable()->index()->after('id');
                $table->string('cj_spu')->nullable()->after('cj_product_id');
                $table->boolean('is_cj_product')->default(false)->after('cj_spu');
                $table->string('cj_delivery_cycle')->nullable()->after('is_cj_product');
                $table->timestamp('cj_synced_at')->nullable()->after('cj_delivery_cycle');
            });
        }

        if (!Schema::hasColumn('product_variants', 'cj_vid')) {
            Schema::table('product_variants', function (Blueprint $table) {
                $table->string('cj_vid')->nullable()->index()->after('product_id');
                $table->string('cj_variant_sku')->nullable()->after('cj_vid');
                $table->decimal('cj_weight', 8, 2)->nullable()->after('cj_variant_sku');
            });
        }

        if (!Schema::hasColumn('orders', 'cj_order_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('cj_order_id')->nullable()->index()->after('id');
                $table->string('cj_order_status')->nullable()->after('cj_order_id');
                $table->string('cj_tracking_number')->nullable()->after('cj_order_status');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('products', 'cj_product_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn(['cj_product_id', 'cj_spu', 'is_cj_product', 'cj_delivery_cycle', 'cj_synced_at']);
            });
        }

        if (Schema::hasColumn('product_variants', 'cj_vid')) {
            Schema::table('product_variants', function (Blueprint $table) {
                $table->dropColumn(['cj_vid', 'cj_variant_sku', 'cj_weight']);
            });
        }

        if (Schema::hasColumn('orders', 'cj_order_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn(['cj_order_id', 'cj_order_status', 'cj_tracking_number']);
            });
        }
    }
};
