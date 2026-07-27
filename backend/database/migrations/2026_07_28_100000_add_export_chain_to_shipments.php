<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Import & Export Logistics — the export chain.
 *
 * The export leg of the SRS reads
 *   Sales Order → Packing List → Shipping Bill → Container Booking → ETD →
 *   Customer Invoice → Dispatch
 * and three of those had nowhere to live: the carrier booking that precedes the
 * containers, the export-specific half of the shipping bill, and the packing
 * list itself.
 *
 * Two additions:
 *
 * 1. Header side-tracks, following the module's existing shape (customs and
 *    payment are already status tracks on `shipments` rather than tables of
 *    their own, because there is exactly one of each per consignment):
 *      - Booking: the carrier's booking reference and the cut-offs the desk
 *        works backwards from. ETD/ETA already exist and stay where they are.
 *      - Shipping bill: the SB number and date are the generic customs entry
 *        columns; what an export adds is the Let Export Order date and the
 *        incentive/valuation figures (FOB, drawback, RoDTEP) that the entry
 *        carries but a bill of entry does not.
 *
 * 2. `shipment_packing_items` — the packing list, one row per group of like
 *    packages. It is a child table rather than a header field because customs,
 *    the carrier and the buyer all read it line by line (marks, HS code, per-box
 *    weights and dimensions), and because a line can be attributed to the box it
 *    was stuffed into. The header's packages / weight / CBM columns become the
 *    roll-up of these lines once any exist (Shipment::syncCargoTotals).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            // Container booking with the carrier — placed before the containers
            // exist, which is why it sits on the header and not on the boxes.
            $table->string('booking_number')->nullable()->after('voyage_number');
            $table->date('booking_date')->nullable()->after('booking_number');
            // Documentation (shipping-instruction) and physical (gate-in) cut-offs.
            $table->date('si_cutoff')->nullable()->after('booking_date');
            $table->date('cargo_cutoff')->nullable()->after('si_cutoff');

            // Shipping bill extras. Duty columns already cover an import's BE;
            // an export's SB instead carries the FOB value it was assessed on,
            // the incentives claimed against it and the Let Export Order.
            $table->decimal('fob_value', 16, 2)->default(0)->after('assessable_value');
            $table->decimal('drawback_amount', 16, 2)->default(0)->after('fob_value');
            $table->decimal('rodtep_amount', 16, 2)->default(0)->after('drawback_amount');
            $table->date('leo_date')->nullable()->after('customs_cleared_at');

            $table->index(['tenant_id', 'cargo_cutoff']);
        });

        Schema::create('shipment_packing_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            // Which box the line was stuffed into, once stuffing is planned. Null
            // while the list is still being built, and nulled rather than deleted
            // if the box goes away, because the cargo is still on the shipment.
            $table->foreignId('shipment_container_id')->nullable()
                ->constrained('shipment_containers')->nullOnDelete();
            // Where the line came from: the order line it packs, and the product
            // behind it. Both optional — a packing list may carry cargo (samples,
            // dunnage, literature) that was never ordered.
            $table->foreignId('sales_item_id')->nullable()->constrained('sales_items')->nullOnDelete();
            $table->foreignId('purchase_item_id')->nullable()->constrained('purchase_items')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            $table->string('description');
            // Shipping marks stencilled on the packages — customs matches the
            // physical cargo to the list by these.
            $table->string('marks_numbers')->nullable();
            $table->string('package_type', 30)->default('carton');
            $table->unsignedInteger('package_count')->default(1);
            $table->decimal('quantity', 14, 3)->default(0);
            $table->string('uom', 20)->nullable();
            $table->decimal('net_weight_kg', 14, 3)->default(0);
            $table->decimal('gross_weight_kg', 14, 3)->default(0);
            // Per-package dimensions; cbm is derived from them × package_count
            // when all three are given, and otherwise taken as entered.
            $table->decimal('length_cm', 10, 2)->nullable();
            $table->decimal('width_cm', 10, 2)->nullable();
            $table->decimal('height_cm', 10, 2)->nullable();
            $table->decimal('cbm', 12, 3)->default(0);
            $table->string('hs_code', 20)->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'shipment_id']);
            $table->index('shipment_container_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_packing_items');

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'cargo_cutoff']);
            $table->dropColumn([
                'booking_number', 'booking_date', 'si_cutoff', 'cargo_cutoff',
                'fob_value', 'drawback_amount', 'rodtep_amount', 'leo_date',
            ]);
        });
    }
};
