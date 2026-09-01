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
            if (!Schema::hasColumn('orders', 'tax_rate')) {
                $table->decimal('tax_rate', 8, 4)->nullable()->after('tax')->default(0);
            }
            if (!Schema::hasColumn('orders', 'tax_details')) {
                $table->json('tax_details')->nullable()->after('tax_rate');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'tax_details')) {
                $table->dropColumn('tax_details');
            }
            if (Schema::hasColumn('orders', 'tax_rate')) {
                $table->dropColumn('tax_rate');
            }
        });
    }
};
