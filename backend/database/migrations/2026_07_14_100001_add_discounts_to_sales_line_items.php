<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-line discount on sales document line items. line_total continues to hold
 * the line's net taxable value plus its tax; discount_amount records only the
 * per-line discount (the overall invoice discount is folded into line_total via
 * proportional distribution and tracked on the header).
 */
return new class extends Migration
{
    private array $tables = ['sales_items', 'sales_quotation_items', 'sales_return_items'];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->enum('discount_type', ['percent', 'flat'])->nullable()->after('unit_price');
                $table->decimal('discount_value', 15, 2)->default(0)->after('discount_type');
                $table->decimal('discount_amount', 15, 2)->default(0)->after('discount_value');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn(['discount_type', 'discount_value', 'discount_amount']);
            });
        }
    }
};
