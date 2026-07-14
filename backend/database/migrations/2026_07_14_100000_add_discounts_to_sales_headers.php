<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom discount + round-off on sales document headers. `total` remains the
 * grand total; the new columns record how it was reached:
 *   subtotal (gross) - item_discount_total - discount_amount = taxable base
 *   taxable + tax_amount, rounded to the nearest rupee (round_off) = total.
 */
return new class extends Migration
{
    private array $tables = ['sales_orders', 'sales_quotations', 'sales_returns'];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->decimal('item_discount_total', 15, 2)->default(0)->after('subtotal');
                $table->enum('discount_type', ['percent', 'flat'])->nullable()->after('item_discount_total');
                $table->decimal('discount_value', 15, 2)->default(0)->after('discount_type');
                $table->decimal('discount_amount', 15, 2)->default(0)->after('discount_value');
                $table->decimal('round_off', 15, 2)->default(0)->after('tax_amount');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn([
                    'item_discount_total', 'discount_type', 'discount_value',
                    'discount_amount', 'round_off',
                ]);
            });
        }
    }
};
