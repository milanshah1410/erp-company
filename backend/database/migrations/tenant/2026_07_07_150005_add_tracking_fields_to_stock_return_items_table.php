<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_return_items', function (Blueprint $table) {
            $table->string('batch_number', 100)->nullable()->after('reason');
            $table->date('expiry_date')->nullable()->after('batch_number');
            $table->json('serials')->nullable()->after('expiry_date');
            $table->json('batch_allocations')->nullable()->after('serials');
        });
    }

    public function down(): void
    {
        Schema::table('stock_return_items', function (Blueprint $table) {
            $table->dropColumn(['batch_number', 'expiry_date', 'serials', 'batch_allocations']);
        });
    }
};
