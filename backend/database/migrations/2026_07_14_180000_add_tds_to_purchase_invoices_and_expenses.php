<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TDS (Tax Deducted at Source) captured on the buy side: the amount we withhold
 * from a vendor bill or a contractor expense and later deposit to the tax
 * authority. `total` / `amount` remain the gross document value; `tds_amount`
 * is informational (net payable to the party = gross - tds).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->string('tds_section')->nullable()->after('tax_amount');
            $table->decimal('tds_rate', 5, 2)->default(0)->after('tds_section');
            $table->decimal('tds_amount', 15, 2)->default(0)->after('tds_rate');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->string('tds_section')->nullable()->after('amount');
            $table->decimal('tds_rate', 5, 2)->default(0)->after('tds_section');
            $table->decimal('tds_amount', 15, 2)->default(0)->after('tds_rate');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->dropColumn(['tds_section', 'tds_rate', 'tds_amount']);
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn(['tds_section', 'tds_rate', 'tds_amount']);
        });
    }
};
