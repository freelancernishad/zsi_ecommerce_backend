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
            if (!Schema::hasColumn('orders', 'order_type')) {
                $table->string('order_type', 20)->default('online')->after('order_number')->index();
            }
            if (!Schema::hasColumn('orders', 'pos_receipt_number')) {
                $table->string('pos_receipt_number', 50)->nullable()->after('order_type')->index();
            }
            if (!Schema::hasColumn('orders', 'pos_cashier_id')) {
                $table->unsignedBigInteger('pos_cashier_id')->nullable()->after('pos_receipt_number');
            }
            if (!Schema::hasColumn('orders', 'tendered_amount')) {
                $table->decimal('tendered_amount', 12, 2)->default(0)->after('total');
            }
            if (!Schema::hasColumn('orders', 'change_amount')) {
                $table->decimal('change_amount', 12, 2)->default(0)->after('tendered_amount');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'order_type',
                'pos_receipt_number',
                'pos_cashier_id',
                'tendered_amount',
                'change_amount'
            ]);
        });
    }
};
