<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distribution & Wholesale plugin schema.
 *
 * Deliberately small. A distributor's documents — orders, despatches, invoices,
 * receipts — are the ones the ERP already has; what was missing was never a
 * table, it was the *terms* a party is supplied on. Two facts had nowhere to
 * live:
 *
 *  1. Credit period. `invoices.due_date` was derived from one workspace-wide
 *     setting, so a distributor running 7-day chemists and 45-day institutions
 *     off the same book had an ageing report that was wrong for both. Terms are
 *     a property of the party, not of the workspace.
 *
 *  2. Supply hold. "Do not despatch to this party until they pay" is a decision
 *     someone makes on a Monday morning, and it is not the same thing as a
 *     credit limit (which is a number that happens to be breached) or as
 *     deactivating the customer (which hides them from the salesman and loses
 *     the ledger). Held-but-active is a real state and now has a column.
 *
 * Both hang off `customers` rather than a side table: they are read on every
 * order confirmation and every despatch, and a join per credit check to carry
 * two columns would buy nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            /**
             * Net credit days for this party. 0 = fall back to the workspace
             * `sales_payment_term_days` setting, which is what every existing
             * customer keeps doing, so no invoice already raised changes its due
             * date and no ageing figure moves on deploy.
             */
            $table->unsignedSmallInteger('payment_terms_days')->default(0)->after('credit_limit');
            /**
             * Supply stopped by a person, with the reason they gave. Separate
             * from `is_active`: a held party is still visited, still has a
             * ledger, still receives statements — they just do not get goods.
             */
            $table->boolean('credit_hold')->default(false)->after('payment_terms_days');
            $table->string('credit_hold_reason')->nullable()->after('credit_hold');
            $table->timestamp('credit_hold_at')->nullable()->after('credit_hold_reason');
            $table->foreignId('credit_hold_by')->nullable()->after('credit_hold_at')
                ->constrained('users')->nullOnDelete();

            /**
             * Credit Control lists held parties first and the ageing screen
             * filters on the flag, both across the whole customer master.
             */
            $table->index(['tenant_id', 'credit_hold'], 'customers_credit_hold_idx');
        });

        Schema::table('invoices', function (Blueprint $table) {
            /**
             * The ageing report walks every unpaid invoice by due date, for one
             * tenant. Without this it is a full scan of the invoice table on a
             * screen a collections desk refreshes all morning.
             */
            $table->index(['tenant_id', 'status', 'due_date'], 'invoices_ageing_idx');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_ageing_idx');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('customers_credit_hold_idx');
            $table->dropConstrainedForeignId('credit_hold_by');
            $table->dropColumn([
                'payment_terms_days', 'credit_hold', 'credit_hold_reason', 'credit_hold_at',
            ]);
        });
    }
};
