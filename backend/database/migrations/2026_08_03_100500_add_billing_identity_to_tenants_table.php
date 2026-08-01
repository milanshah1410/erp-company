<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Landlord migration: the tenant's identity *as the platform's customer*.
 *
 * The workspace already knows its own GSTIN — but as the seller on its own sales
 * invoices, inside its tenant data. When the SaaS operator bills the workspace
 * the roles swap: we need the GSTIN to raise a B2B tax invoice against, and the
 * state that GSTIN belongs to, because that is what decides CGST+SGST vs IGST.
 *
 * `billing_state_code` is a GST state code ('24' = Gujarat). It is derived from
 * the GSTIN when one is present and only entered by hand for an unregistered
 * customer, where the place of supply is the customer's address instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('billing_legal_name')->nullable()->after('company_name');
            $table->string('billing_gstin', 20)->nullable()->after('billing_legal_name');
            $table->string('billing_state_code', 2)->nullable()->after('billing_gstin');
            $table->text('billing_address')->nullable()->after('billing_state_code');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['billing_legal_name', 'billing_gstin', 'billing_state_code', 'billing_address']);
        });
    }
};
