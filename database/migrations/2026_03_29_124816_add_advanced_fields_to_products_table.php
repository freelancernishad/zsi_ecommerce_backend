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
            $table->decimal('cost_per_item', 15, 2)->nullable()->after('original_price');
            $table->string('barcode')->nullable()->after('sku');
            $table->decimal('weight', 10, 2)->nullable();
            $table->string('weight_unit')->default('kg');
            $table->decimal('height', 10, 2)->nullable();
            $table->string('height_unit')->default('ft');
            $table->decimal('width', 10, 2)->nullable();
            $table->string('width_unit')->default('ft');
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->string('url_handle')->nullable();
            $table->boolean('continue_selling')->default(false);
            $table->boolean('is_physical')->default(true);
            $table->boolean('charge_tax')->default(true);
            $table->foreignId('sub_category_id')->nullable()->constrained('categories')->onDelete('set null');
            $table->foreignId('sub_sub_category_id')->nullable()->constrained('categories')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['sub_category_id']);
            $table->dropForeign(['sub_sub_category_id']);
            $table->dropColumn([
                'cost_per_item', 'barcode', 'weight', 'weight_unit', 'height', 'height_unit', 
                'width', 'width_unit', 'seo_title', 'seo_description', 'url_handle', 
                'continue_selling', 'is_physical', 'charge_tax', 'sub_category_id', 'sub_sub_category_id'
            ]);
        });
    }
};
