<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('products', 'import_source')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('import_source')->nullable()->after('is_cj_product')->index();
            });
        }

        // Backfill existing products
        DB::table('products')
            ->where(function ($q) {
                $q->where('is_cj_product', 1)
                  ->orWhereNotNull('cj_product_id');
            })
            ->update(['import_source' => 'cj']);

        DB::table('products')
            ->whereNull('import_source')
            ->whereNotNull('url_handle')
            ->where('url_handle', '!=', '')
            ->update(['import_source' => 'shopify']);

        DB::table('products')
            ->whereNull('import_source')
            ->update(['import_source' => 'manual']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('products', 'import_source')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('import_source');
            });
        }
    }
};
