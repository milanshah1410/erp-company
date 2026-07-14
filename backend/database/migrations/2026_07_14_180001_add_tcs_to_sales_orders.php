<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TCS (Tax Collected at Source) captured on the sell side: the amount we collect
 * from a customer over and above the invoice value (e.g. 206C(1H)) and deposit
 * to the tax authority. Stored on the sales order header alongside tax_amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('tcs_section')->nullable()->after('tax_amount');
            $table->decimal('tcs_rate', 5, 2)->default(0)->after('tcs_section');
            $table->decimal('tcs_amount', 15, 2)->default(0)->after('tcs_rate');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['tcs_section', 'tcs_rate', 'tcs_amount']);
        });
    }
};
