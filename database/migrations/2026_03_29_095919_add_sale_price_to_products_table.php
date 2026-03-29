<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Add sale_price column after original_price
            $table->decimal('sale_price', 15, 2)->nullable()->after('original_price');

            // Extend status enum to include 'archived'
            $table->enum('status', ['draft', 'published', 'archived'])
                  ->default('published')
                  ->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('sale_price');

            $table->enum('status', ['draft', 'published'])
                  ->default('published')
                  ->change();
        });
    }
};
