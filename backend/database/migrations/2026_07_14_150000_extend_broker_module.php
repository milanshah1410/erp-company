<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Broker (Dalal) Advanced — schema for multi-broker invoices, product/customer
 * commission rates, TDS-aware settlements and a broker wallet.
 *
 *  - brokers gains a PAN + default TDS rate (withheld at settlement) and a
 *    maintained wallet_balance.
 *  - broker_commission_rates: per-broker rate overrides keyed by product or
 *    customer. Resolution precedence at accrual: invoice-broker override ->
 *    product rate -> customer rate -> broker default.
 *  - sales_order_brokers / invoice_brokers: the "many brokers per deal" pivots.
 *    Captured on the sales order, copied onto the invoice at delivery. Each row
 *    may override the rate and carries a share_percent (how much of the deal
 *    that broker earns on), so two dalals can split a single invoice.
 *  - broker_wallet_transactions: a running money ledger per broker. Commission
 *    accrual credits it, reversals/settlements debit it, and manual top-ups /
 *    advances / adjustments move it too. broker.wallet_balance mirrors the tail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brokers', function (Blueprint $table) {
            $table->string('pan_number', 20)->nullable()->after('gst_number');
            $table->decimal('tds_percent', 5, 2)->default(0)->after('fixed_commission');
            $table->decimal('wallet_balance', 15, 2)->default(0)->after('opening_balance');
        });

        Schema::create('broker_commission_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broker_id')->constrained('brokers')->cascadeOnDelete();
            $table->enum('scope', ['product', 'customer']);
            $table->foreignId('product_id')->nullable()->constrained('products')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();
            $table->decimal('commission_percent', 5, 2)->default(0);
            $table->timestamps();

            $table->index(['broker_id', 'scope']);
            $table->index(['broker_id', 'product_id']);
            $table->index(['broker_id', 'customer_id']);
        });

        $pivot = function (Blueprint $table, string $parent) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId("{$parent}_id")->constrained()->cascadeOnDelete();
            $table->foreignId('broker_id')->constrained('brokers')->cascadeOnDelete();
            // NULL = fall back to rate resolution / broker default.
            $table->decimal('commission_percent', 5, 2)->nullable();
            $table->decimal('fixed_commission', 15, 2)->nullable();
            // Portion of the deal this broker earns on (two brokers can split 50/50).
            $table->decimal('share_percent', 5, 2)->default(100);
            $table->timestamps();

            $table->unique(["{$parent}_id", 'broker_id']);
            $table->index('broker_id');
        };

        Schema::create('sales_order_brokers', function (Blueprint $table) use ($pivot) {
            $pivot($table, 'sales_order');
        });

        Schema::create('invoice_brokers', function (Blueprint $table) use ($pivot) {
            $pivot($table, 'invoice');
        });

        Schema::create('broker_wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broker_id')->constrained('brokers')->cascadeOnDelete();
            $table->enum('type', [
                'commission', 'reversal', 'settlement', 'topup', 'advance', 'adjustment',
            ]);
            // Signed: positive credits the broker (owed more), negative debits.
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->nullableMorphs('reference');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['broker_id', 'created_at']);
        });

        Schema::table('broker_settlements', function (Blueprint $table) {
            // amount stays the GROSS commission cleared (drives FIFO + outstanding).
            $table->decimal('tds_percent', 5, 2)->default(0)->after('amount');
            $table->decimal('tds_amount', 15, 2)->default(0)->after('tds_percent');
            $table->decimal('net_paid', 15, 2)->nullable()->after('tds_amount');
            // "2026-07" — set when this payout closes a monthly statement.
            $table->string('period_month', 7)->nullable()->after('settlement_date');
        });

        // Backfill: seed each broker's wallet balance from opening + net earned
        // commission - settled, and default net_paid to the gross amount.
        DB::statement('UPDATE broker_settlements SET net_paid = amount WHERE net_paid IS NULL');

        DB::table('brokers')->orderBy('id')->get(['id', 'opening_balance'])->each(function ($b) {
            $earned = (float) DB::table('broker_commissions')->where('broker_id', $b->id)->sum('commission_amount');
            $settled = (float) DB::table('broker_settlements')->where('broker_id', $b->id)->sum('amount');
            DB::table('brokers')->where('id', $b->id)->update([
                'wallet_balance' => round((float) $b->opening_balance + $earned - $settled, 2),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('broker_settlements', function (Blueprint $table) {
            $table->dropColumn(['tds_percent', 'tds_amount', 'net_paid', 'period_month']);
        });
        Schema::dropIfExists('broker_wallet_transactions');
        Schema::dropIfExists('invoice_brokers');
        Schema::dropIfExists('sales_order_brokers');
        Schema::dropIfExists('broker_commission_rates');
        Schema::table('brokers', function (Blueprint $table) {
            $table->dropColumn(['pan_number', 'tds_percent', 'wallet_balance']);
        });
    }
};
