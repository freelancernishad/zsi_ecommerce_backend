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
            if (!Schema::hasColumn('products', 'amazon_asin')) {
                $table->string('amazon_asin')->nullable()->index();
            }
            if (!Schema::hasColumn('products', 'amazon_url')) {
                $table->text('amazon_url')->nullable();
            }
            if (!Schema::hasColumn('products', 'is_amazon_product')) {
                $table->boolean('is_amazon_product')->default(false);
            }
            if (!Schema::hasColumn('products', 'amazon_synced_at')) {
                $table->timestamp('amazon_synced_at')->nullable();
            }
        });

        Schema::table('product_variants', function (Blueprint $table) {
            if (!Schema::hasColumn('product_variants', 'amazon_asin')) {
                $table->string('amazon_asin')->nullable();
            }
            if (!Schema::hasColumn('product_variants', 'amazon_variant_sku')) {
                $table->string('amazon_variant_sku')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['amazon_asin', 'amazon_url', 'is_amazon_product', 'amazon_synced_at']);
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn(['amazon_asin', 'amazon_variant_sku']);
        });
    }
};
