<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Import & Export Logistics — the ERP documents a shipment produces.
 *
 * `purchase_order_id` / `sales_order_id` already record what a shipment came
 * *from*. These four record what it turns into on the way through the ERP, so
 * the Import (PO → supplier invoice → warehouse receipt) and Export (SO →
 * customer invoice → dispatch) chains are navigable in both directions and a
 * board can tell "arrived but not received" from "received into stock".
 *
 * All nullable and singular: a consignment normally lands in one receipt and
 * bills on one invoice. A second receipt against the same PO is still allowed —
 * it just isn't the one this shipment points at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            // Import leg.
            $table->foreignId('purchase_invoice_id')->nullable()->after('sales_order_id')
                ->constrained('purchase_invoices')->nullOnDelete();
            $table->foreignId('goods_receipt_id')->nullable()->after('purchase_invoice_id')
                ->constrained('goods_receipts')->nullOnDelete();
            // Export leg.
            $table->foreignId('invoice_id')->nullable()->after('goods_receipt_id')
                ->constrained('invoices')->nullOnDelete();
            $table->foreignId('dispatch_plan_id')->nullable()->after('invoice_id')
                ->constrained('dispatch_plans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropForeign(['purchase_invoice_id']);
            $table->dropForeign(['goods_receipt_id']);
            $table->dropForeign(['invoice_id']);
            $table->dropForeign(['dispatch_plan_id']);
            $table->dropColumn(['purchase_invoice_id', 'goods_receipt_id', 'invoice_id', 'dispatch_plan_id']);
        });
    }
};
