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
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'aliexpress_product_id')) {
                $table->string('aliexpress_product_id')->nullable()->index();
            }
            if (!Schema::hasColumn('products', 'aliexpress_url')) {
                $table->text('aliexpress_url')->nullable();
            }
            if (!Schema::hasColumn('products', 'is_aliexpress_product')) {
                $table->boolean('is_aliexpress_product')->default(false);
            }
            if (!Schema::hasColumn('products', 'aliexpress_synced_at')) {
                $table->timestamp('aliexpress_synced_at')->nullable();
            }
            if (!Schema::hasColumn('products', 'aliexpress_store_name')) {
                $table->string('aliexpress_store_name')->nullable();
            }
        });

        Schema::table('product_variants', function (Blueprint $table) {
            if (!Schema::hasColumn('product_variants', 'aliexpress_sku_id')) {
                $table->string('aliexpress_sku_id')->nullable();
            }
            if (!Schema::hasColumn('product_variants', 'aliexpress_variant_sku')) {
                $table->string('aliexpress_variant_sku')->nullable();
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'aliexpress_order_id')) {
                $table->string('aliexpress_order_id')->nullable()->index();
            }
            if (!Schema::hasColumn('orders', 'aliexpress_order_status')) {
                $table->string('aliexpress_order_status')->nullable();
            }
            if (!Schema::hasColumn('orders', 'aliexpress_tracking_number')) {
                $table->string('aliexpress_tracking_number')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'aliexpress_product_id',
                'aliexpress_url',
                'is_aliexpress_product',
                'aliexpress_synced_at',
                'aliexpress_store_name',
            ]);
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn(['aliexpress_sku_id', 'aliexpress_variant_sku']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['aliexpress_order_id', 'aliexpress_order_status', 'aliexpress_tracking_number']);
        });
    }
};
