<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'sync_markup_type')) {
                $table->string('sync_markup_type', 20)->nullable()->after('cost_per_item');
            }
            if (!Schema::hasColumn('products', 'sync_markup_value')) {
                $table->decimal('sync_markup_value', 10, 2)->nullable()->after('sync_markup_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'sync_markup_value')) {
                $table->dropColumn('sync_markup_value');
            }
            if (Schema::hasColumn('products', 'sync_markup_type')) {
                $table->dropColumn('sync_markup_type');
            }
        });
    }
};
