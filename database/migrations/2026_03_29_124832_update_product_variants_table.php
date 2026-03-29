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
        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('combination_label')->after('product_id')->nullable(); // e.g. "Small, Red"
            $table->decimal('price', 15, 2)->nullable()->after('combination_label');
            $table->decimal('discount_price', 15, 2)->nullable()->after('price');
            $table->string('sku')->nullable()->after('discount_price');
            $table->integer('available_quantity')->default(0)->after('sku');
            
            // Removing old hardcoded columns if they are no longer needed
            // For now, I'll keep them but allow null to avoid breaking existing data if any
            $table->string('color')->nullable()->change();
            $table->string('size')->nullable()->change();
            $table->decimal('price_adjustment', 15, 2)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn(['combination_label', 'price', 'discount_price', 'sku', 'available_quantity']);
        });
    }
};
