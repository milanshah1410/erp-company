<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attribute a logistics cost to a single container.
 *
 * Most shipment costs (freight, CHA, documentation) belong to the consignment
 * as a whole, so the column stays null. Detention and demurrage do not: the
 * carrier bills them per box, per day past its own free-days window, and the
 * desk has to answer "which container cost us this". Without the link a
 * shipment with six boxes carries six indistinguishable detention lines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_expenses', function (Blueprint $table) {
            $table->foreignId('shipment_container_id')->nullable()->after('shipment_id')
                ->constrained('shipment_containers')->nullOnDelete();
            $table->index(['shipment_container_id', 'expense_type']);
        });
    }

    public function down(): void
    {
        Schema::table('shipment_expenses', function (Blueprint $table) {
            $table->dropForeign(['shipment_container_id']);
            $table->dropIndex(['shipment_container_id', 'expense_type']);
            $table->dropColumn('shipment_container_id');
        });
    }
};
