<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->enum('fulfillment_stage', ['pending', 'picked', 'packed', 'shipped'])->default('pending')->after('channel');
            $table->timestamp('picked_at')->nullable()->after('fulfillment_stage');
            $table->timestamp('packed_at')->nullable()->after('picked_at');
            $table->timestamp('shipped_at')->nullable()->after('packed_at');
            $table->foreignId('picked_by')->nullable()->after('shipped_at')->constrained('users')->nullOnDelete();
            $table->foreignId('packed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('shipped_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('picked_by');
            $table->dropConstrainedForeignId('packed_by');
            $table->dropConstrainedForeignId('shipped_by');
            $table->dropColumn(['fulfillment_stage', 'picked_at', 'packed_at', 'shipped_at']);
        });
    }
};
