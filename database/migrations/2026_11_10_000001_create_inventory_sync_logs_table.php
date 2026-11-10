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
        Schema::create('inventory_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->string('product_name')->nullable();
            $table->string('supplier')->index(); // 'aliexpress', 'cjdropshipping', 'amazon'
            $table->string('supplier_id')->nullable()->index();
            $table->string('status')->default('success')->index(); // 'success', 'warning', 'error'
            $table->boolean('changes_detected')->default(false)->index();
            $table->integer('old_stock')->nullable();
            $table->integer('new_stock')->nullable();
            $table->decimal('old_price', 10, 2)->nullable();
            $table->decimal('new_price', 10, 2)->nullable();
            $table->json('details')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_sync_logs');
    }
};

