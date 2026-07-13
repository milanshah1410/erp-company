<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->foreignId('broker_id')->nullable()->after('customer_id')
                ->constrained('brokers')->nullOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('broker_id')->nullable()->after('customer_id')
                ->constrained('brokers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('broker_id');
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('broker_id');
        });
    }
};
