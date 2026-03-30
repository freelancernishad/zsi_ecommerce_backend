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
            // Drop the foreign key constraint that binds causer_id strictly to 'users' table
            // This is necessary because Admins (from 'admins' table) also cause logs.
            $table->dropForeign(['causer_id']);
            
            // Ensure the column is still there but as a simple bigInteger
            $table->unsignedBigInteger('causer_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_logs', function (Blueprint $table) {
            $table->foreign('causer_id')->references('id')->on('users')->onDelete('set null');
        });
    }
};
