<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Import & Export Logistics module — Shipments.
 *
 * The operational half of the module, built on the masters created in
 * 2026_07_27_100000_create_exim_master_tables (countries, ports, carriers,
 * forwarders, CHA, transporters) and the overseas party flags added in
 * 2026_07_27_100100_add_overseas_fields_to_parties.
 *
 * One document — `shipments` — models both legs of the workflow
 * (PO → import shipment → container → customs → warehouse, and
 * SO → export shipment → container → customs → dispatch → payment).
 * `direction` is the discriminator; every board in the module (Active Imports,
 * Active Exports, ETA/ETD, Pending Documents, Pending Customs, Pending Payments,
 * Container Status, Shipment Profit, Logistics Expenses) is a filtered
 * projection of this table plus its children.
 *
 * The header carries the trade block (incoterm, ports, carrier, B/L or AWB), the
 * schedule block (ETD/ETA vs actual ATD/ATA), the customs block (bill of entry
 * for imports / shipping bill for exports, ICEGATE reference and the duty
 * breakdown) and the money block (foreign invoice value + exchange rate, settled
 * so far, payment status). Customs is 1:1 with a shipment — one BE or one SB per
 * consignment — so it stays on the header rather than earning its own table.
 *
 * Five child tables hang off it:
 *   shipment_containers — container / ULD tracking incl. damage & empty return
 *   shipment_documents  — the trade document set with due dates
 *   shipment_events     — the milestone log behind the timeline
 *   shipment_expenses   — freight, THC, CFS, CHA, duty, detention …
 *   shipment_payments   — settlements of the goods value, inbound or outbound
 *
 * Shipment profit is derived, never stored: revenue (export invoice value) less
 * goods cost, duty and absorbed logistics cost, all in base currency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('shipment_number');
            $table->enum('direction', ['import', 'export']);
            $table->enum('status', [
                'draft', 'booked', 'in_transit', 'arrived', 'customs', 'cleared', 'delivered', 'closed', 'cancelled',
            ])->default('draft');
            $table->date('shipment_date');

            // Counterparty: an overseas supplier for imports, an overseas customer
            // for exports — the same party masters the PO / SO already point at
            // (suppliers.is_overseas / customers.is_overseas). The order document
            // is optional: a shipment can be raised before it exists.
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            // Where import cargo lands / export cargo ships from, so the warehouse
            // receipt and dispatch legs of the workflow have somewhere to point.
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();

            // Trade terms & transport — all drawn from the exim masters.
            $table->enum('mode', ['sea', 'air', 'road', 'rail', 'courier'])->default('sea');
            $table->enum('incoterm', ['EXW', 'FCA', 'FOB', 'CFR', 'CIF', 'CIP', 'DAP', 'DDP', 'other'])->default('FOB');
            $table->foreignId('shipping_line_id')->nullable()->constrained('shipping_lines')->nullOnDelete();
            $table->foreignId('airline_id')->nullable()->constrained('airlines')->nullOnDelete();
            $table->foreignId('freight_forwarder_id')->nullable()->constrained('freight_forwarders')->nullOnDelete();
            $table->foreignId('cha_agent_id')->nullable()->constrained('cha_agents')->nullOnDelete();
            $table->foreignId('transporter_id')->nullable()->constrained('transporters')->nullOnDelete();
            $table->string('bl_awb_number')->nullable();    // B/L (sea) or AWB (air)
            $table->date('bl_awb_date')->nullable();
            $table->string('vessel_name')->nullable();
            $table->string('voyage_number')->nullable();
            $table->foreignId('origin_country_id')->nullable()->constrained('countries')->nullOnDelete();
            $table->foreignId('destination_country_id')->nullable()->constrained('countries')->nullOnDelete();
            $table->foreignId('port_of_loading_id')->nullable()->constrained('ports')->nullOnDelete();
            $table->foreignId('port_of_discharge_id')->nullable()->constrained('ports')->nullOnDelete();
            $table->string('final_place')->nullable();      // door / factory address

            // Schedule — estimated vs actual (ETA / ETD board)
            $table->date('etd')->nullable();
            $table->date('eta')->nullable();
            $table->date('atd')->nullable();
            $table->date('ata')->nullable();

            // Cargo
            $table->text('goods_description')->nullable();
            $table->unsignedInteger('packages')->default(0);
            $table->decimal('gross_weight_kg', 14, 3)->nullable();
            $table->decimal('net_weight_kg', 14, 3)->nullable();
            $table->decimal('cbm', 12, 3)->nullable();

            // Money — invoice_value, freight_amount and insurance_amount are in
            // the shipment's foreign `currency`; exchange_rate converts them to
            // the tenant's base currency, which every roll-up uses.
            $table->string('currency', 3)->default('USD');
            $table->decimal('exchange_rate', 14, 6)->default(1);
            $table->string('invoice_number')->nullable();
            $table->date('invoice_date')->nullable();
            $table->decimal('invoice_value', 16, 2)->default(0);
            $table->decimal('freight_amount', 16, 2)->default(0);
            $table->decimal('insurance_amount', 16, 2)->default(0);
            // Cost of the goods themselves in base currency — the profit board's
            // COGS input for exports, the pre-landed value for imports.
            $table->decimal('goods_cost', 16, 2)->default(0);

            // Customs (Pending Customs board). Duty amounts are base currency.
            $table->enum('customs_status', ['pending', 'filed', 'assessed', 'duty_paid', 'cleared', 'on_hold'])->default('pending');
            $table->enum('customs_entry_type', ['bill_of_entry', 'shipping_bill'])->nullable();
            $table->string('customs_entry_number')->nullable();
            $table->date('customs_entry_date')->nullable();
            $table->string('icegate_reference')->nullable();
            $table->decimal('assessable_value', 16, 2)->default(0);
            $table->decimal('duty_bcd', 16, 2)->default(0);         // basic customs duty
            $table->decimal('duty_igst', 16, 2)->default(0);
            $table->decimal('duty_cess', 16, 2)->default(0);        // incl. social welfare surcharge
            $table->decimal('duty_other', 16, 2)->default(0);
            $table->decimal('duty_total', 16, 2)->default(0);
            $table->timestamp('duty_paid_at')->nullable();
            $table->timestamp('customs_cleared_at')->nullable();

            // Settlement of the goods value (Pending Payments board). Base currency.
            $table->enum('payment_status', ['unpaid', 'partial', 'paid'])->default('unpaid');
            $table->decimal('amount_settled', 16, 2)->default(0);
            $table->date('payment_due_date')->nullable();
            $table->string('lc_number')->nullable();
            $table->date('lc_expiry')->nullable();

            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'shipment_number']);
            $table->index(['tenant_id', 'direction', 'status']);
            $table->index(['tenant_id', 'eta']);
            $table->index(['tenant_id', 'etd']);
            $table->index(['tenant_id', 'customs_status']);
            $table->index(['tenant_id', 'payment_status']);
        });

        Schema::create('shipment_containers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->string('container_number');
            $table->enum('container_type', ['20ft', '40ft', '40hc', '45hc', 'lcl', 'uld', 'other'])->default('20ft');
            $table->string('seal_number')->nullable();
            $table->enum('status', [
                'planned', 'stuffed', 'gated_in', 'loaded', 'in_transit',
                'discharged', 'gated_out', 'delivered', 'empty_returned',
            ])->default('planned');
            $table->string('current_location')->nullable();
            $table->timestamp('status_updated_at')->nullable();
            $table->unsignedInteger('packages')->default(0);
            $table->decimal('gross_weight_kg', 14, 3)->nullable();
            $table->decimal('net_weight_kg', 14, 3)->nullable();
            $table->decimal('cbm', 12, 3)->nullable();
            // Free days end on detention_free_until; past it the carrier bills
            // detention / demurrage, which the Container Status board flags.
            $table->unsignedInteger('free_days')->default(0);
            $table->date('detention_free_until')->nullable();
            $table->date('empty_returned_on')->nullable();
            // Damage found on survey — the claim the desk has to raise.
            $table->boolean('is_damaged')->default(false);
            $table->text('damage_remarks')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'container_number']);
        });

        Schema::create('shipment_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            // Free-form string rather than an enum: the required document set
            // differs per country and commodity. App\Support\TradeCatalogue holds
            // the catalogue the UI offers and the API validates against.
            $table->string('doc_type');
            $table->string('doc_number')->nullable();
            $table->date('doc_date')->nullable();
            $table->date('due_date')->nullable();
            $table->enum('status', ['pending', 'received', 'submitted', 'verified', 'rejected'])->default('pending');
            $table->boolean('is_mandatory')->default(true);
            $table->string('file_path')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'due_date']);
        });

        Schema::create('shipment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->string('event_type');
            $table->timestamp('event_at');
            $table->string('location')->nullable();
            $table->text('description')->nullable();
            // 'system' rows are written by status / customs / container / document
            // transitions; 'manual' rows are typed in on the timeline screen.
            $table->enum('source', ['manual', 'system'])->default('manual');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['shipment_id', 'event_at']);
        });

        Schema::create('shipment_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->string('expense_type');
            $table->date('expense_date');
            // The biller can be any of the exim masters or an ordinary supplier,
            // so it is stored as type + id (no cross-table FK) plus the resolved
            // name, which the controller fills from the master it points at.
            $table->string('vendor_type', 30)->nullable();
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->string('vendor_name')->nullable();
            $table->string('invoice_number')->nullable();
            // amount is in `currency`; base_amount = amount * exchange_rate and is
            // what every roll-up (profit, dashboard, pending payments) sums.
            $table->string('currency', 3)->default('INR');
            $table->decimal('exchange_rate', 14, 6)->default(1);
            $table->decimal('amount', 16, 2)->default(0);
            $table->decimal('base_amount', 16, 2)->default(0);
            $table->enum('payment_status', ['unpaid', 'partial', 'paid'])->default('unpaid');
            $table->decimal('paid_amount', 16, 2)->default(0);
            $table->date('due_date')->nullable();
            // Recoverable from the counterparty (freight collect, DDP terms) —
            // excluded from the profit board's cost side.
            $table->boolean('is_billable')->default(false);
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'expense_type']);
            $table->index(['tenant_id', 'payment_status']);
            $table->index(['tenant_id', 'expense_date']);
            $table->index(['vendor_type', 'vendor_id']);
        });

        Schema::create('shipment_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            // inbound = export proceeds received; outbound = remitted to the
            // overseas supplier. Both settle the shipment's goods value.
            $table->enum('direction', ['inbound', 'outbound']);
            $table->date('payment_date');
            $table->string('currency', 3)->default('INR');
            $table->decimal('exchange_rate', 14, 6)->default(1);
            $table->decimal('amount', 16, 2)->default(0);
            $table->decimal('base_amount', 16, 2)->default(0);
            $table->enum('mode', ['bank_transfer', 'lc', 'cheque', 'cash', 'upi', 'other'])->default('bank_transfer');
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->string('reference')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_payments');
        Schema::dropIfExists('shipment_expenses');
        Schema::dropIfExists('shipment_events');
        Schema::dropIfExists('shipment_documents');
        Schema::dropIfExists('shipment_containers');
        Schema::dropIfExists('shipments');
    }
};
