<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_returns', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('customer_id')->constrained('suppliers')->nullOnDelete();
            $table->foreignId('goods_receipt_id')->nullable()->after('supplier_id')->constrained('goods_receipts')->nullOnDelete();
            $table->string('debit_note_number')->nullable()->after('goods_receipt_id');
        });

        // Raw MODIFY is MySQL-only; SQLite (test DB) stores enums as strings.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE stock_returns MODIFY return_type ENUM('customer_return', 'vendor_return', 'other') NOT NULL DEFAULT 'other'");
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE stock_returns MODIFY return_type ENUM('customer_return', 'other') NOT NULL DEFAULT 'other'");
        }

        Schema::table('stock_returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('goods_receipt_id');
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn('debit_note_number');
        });
    }
};
