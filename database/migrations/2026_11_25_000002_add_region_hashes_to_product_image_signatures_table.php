<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_image_signatures') && !Schema::hasColumn('product_image_signatures', 'region_hashes')) {
            Schema::table('product_image_signatures', function (Blueprint $table) {
                $table->json('region_hashes')->nullable()->after('color_hist');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('product_image_signatures') && Schema::hasColumn('product_image_signatures', 'region_hashes')) {
            Schema::table('product_image_signatures', function (Blueprint $table) {
                $table->dropColumn('region_hashes');
            });
        }
    }
};
