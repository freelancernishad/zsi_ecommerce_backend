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
        Schema::table('user_logs', function (Blueprint $table) {
            $table->string('category')->nullable()->after('causer_name');
            $table->string('device')->nullable()->after('metadata');
            $table->string('platform')->nullable()->after('device');
            $table->string('browser')->nullable()->after('platform');
            $table->boolean('is_success')->default(true)->after('browser');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_logs', function (Blueprint $table) {
            $table->dropColumn(['category', 'device', 'platform', 'browser', 'is_success']);
        });
    }
};
