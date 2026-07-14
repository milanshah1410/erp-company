<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link sales orders to the field salesman who booked them. Powers "Order
 * Collection" attribution and target-achievement / incentive calculation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->foreignId('salesman_id')->nullable()->after('broker_id')
                ->constrained('salesmen')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('salesman_id');
        });
    }
};
