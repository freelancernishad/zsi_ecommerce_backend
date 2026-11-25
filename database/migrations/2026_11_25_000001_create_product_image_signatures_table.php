<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('product_image_signatures')) {
            Schema::create('product_image_signatures', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_id')->unique();
                $table->string('image_url', 1000);
                $table->string('dhash', 16);
                $table->json('color_hist');
                $table->timestamp('indexed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_image_signatures');
    }
};
