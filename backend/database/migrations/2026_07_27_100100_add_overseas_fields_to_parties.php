<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Import & Export Logistics module — "Overseas Suppliers" and "Overseas
 * Customers" masters.
 *
 * These are not separate party tables: an overseas supplier is still the
 * supplier a purchase order is raised on, and an overseas customer is still the
 * customer a sales order bills. Duplicating them would break the PO → import
 * shipment and SO → export shipment links the module is built around. Instead
 * the existing masters gain the trade attributes an import/export party needs,
 * and `is_overseas` splits them into their own screens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->boolean('is_overseas')->default(false)->after('gst_number');
            $table->foreignId('country_id')->nullable()->after('country')->constrained('countries')->nullOnDelete();
            $table->string('currency_code', 3)->nullable()->after('country_id');
            $table->string('incoterm', 10)->nullable()->after('currency_code');   // FOB, CIF, EXW…
            // Default port the supplier ships out of.
            $table->foreignId('port_id')->nullable()->after('incoterm')->constrained('ports')->nullOnDelete();
            $table->string('swift_code', 20)->nullable()->after('port_id');
            $table->string('bank_details')->nullable()->after('swift_code');

            $table->index(['tenant_id', 'is_overseas']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('is_overseas')->default(false)->after('gst_number');
            $table->foreignId('country_id')->nullable()->after('country')->constrained('countries')->nullOnDelete();
            $table->string('currency_code', 3)->nullable()->after('country_id');
            $table->string('incoterm', 10)->nullable()->after('currency_code');
            // Default port the customer takes delivery at.
            $table->foreignId('port_id')->nullable()->after('incoterm')->constrained('ports')->nullOnDelete();
            $table->string('lc_terms')->nullable()->after('port_id');             // letter-of-credit terms
            $table->string('iec_code', 20)->nullable()->after('lc_terms');        // buyer's import-export code

            $table->index(['tenant_id', 'is_overseas']);
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropForeign(['country_id']);
            $table->dropForeign(['port_id']);
            $table->dropIndex(['tenant_id', 'is_overseas']);
            $table->dropColumn([
                'is_overseas', 'country_id', 'currency_code', 'incoterm',
                'port_id', 'swift_code', 'bank_details',
            ]);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['country_id']);
            $table->dropForeign(['port_id']);
            $table->dropIndex(['tenant_id', 'is_overseas']);
            $table->dropColumn([
                'is_overseas', 'country_id', 'currency_code', 'incoterm',
                'port_id', 'lc_terms', 'iec_code',
            ]);
        });
    }
};
