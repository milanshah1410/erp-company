<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Service Management: a repair/installation job taken in against a customer's
 * item, worked by a technician, settled with parts and labour.
 *
 * Billing is deliberately *not* re-implemented. A completed job raises an
 * ordinary Sales Order on a new 'service' channel and invoices it, exactly as
 * the POS and e-commerce paths do — so GST, payments, receivables, ledgers and
 * every sales report pick the work up without knowing services exist.
 *
 * Labour is billed as a product flagged `is_service`: it prices and taxes like
 * any other line but never touches stock. That keeps one line model for the
 * whole document instead of a parallel "charges" concept the rest of the ERP
 * would have to learn about.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A non-stock, billable item (labour, visit charge, installation). Kept
        // on products rather than a separate table so pricing, HSN/SAC, tax and
        // the existing pickers all work unchanged.
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_service')->default(false)->after('has_variants');
        });

        // Annual maintenance contracts. A job may be booked against one, which
        // is what makes it non-billable: the customer already paid for the year.
        Schema::create('service_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('contract_number');
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('title');
            $table->date('start_date');
            $table->date('end_date');
            // 0 = unlimited visits for the term; anything higher is a quota that
            // service jobs draw down.
            $table->unsignedInteger('visits_allowed')->default(0);
            $table->unsignedInteger('visits_used')->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            $table->enum('status', ['active', 'expired', 'cancelled'])->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'contract_number']);
            $table->index(['tenant_id', 'customer_id']);
            $table->index('status');
        });

        Schema::create('service_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('job_number');
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('service_contract_id')->nullable()->constrained('service_contracts')->nullOnDelete();
            // Where spare parts are drawn from. Nullable: a job may be pure
            // labour, and an on-site visit has no warehouse until parts are used.
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('employees')->nullOnDelete();

            $table->enum('status', [
                'received', 'assigned', 'in_progress', 'on_hold',
                'completed', 'delivered', 'cancelled',
            ])->default('received');
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');

            // The customer's item under repair. Free text on purpose — it is
            // usually someone else's product, not something in our catalogue.
            $table->string('item_name');
            $table->string('item_serial')->nullable();
            $table->text('complaint')->nullable();
            $table->text('diagnosis')->nullable();
            $table->text('resolution')->nullable();

            // Warranty and contract work is done but never billed.
            $table->boolean('is_warranty')->default(false);

            $table->timestamp('received_at')->nullable();
            $table->timestamp('promised_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            // Billing snapshot, priced the same way every other document is.
            $table->decimal('labour_charge', 15, 2)->default(0);
            $table->decimal('labour_tax_percent', 5, 2)->default(0);
            $table->decimal('parts_total', 15, 2)->default(0);
            $table->enum('discount_type', ['percent', 'flat'])->nullable();
            $table->decimal('discount_value', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('round_off', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);

            // Set once the job is billed; both nullable because warranty and
            // contract jobs complete without ever raising a document.
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'job_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'assigned_to']);
        });

        Schema::create('service_job_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_job_id')->constrained('service_jobs')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->enum('discount_type', ['percent', 'flat'])->nullable();
            $table->decimal('discount_value', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
            // Whether this line has already left the warehouse. Parts are issued
            // when the technician actually fits them, which is not when the line
            // was planned — so the flag is per line, not per job.
            $table->boolean('is_issued')->default(false);
            $table->timestamps();

            $table->index('service_job_id');
        });

        if ($this->isMysql()) {
            // Spare parts leaving the warehouse into a repair.
            $types = $this->ledgerTypes();
            $types[] = 'service_issue';
            $this->setLedgerTypes($types);

            DB::statement("ALTER TABLE sales_orders MODIFY channel ENUM('standard','pos','b2b','ecommerce','service') NOT NULL DEFAULT 'standard'");
        }
    }

    public function down(): void
    {
        if ($this->isMysql()) {
            DB::table('sales_orders')->where('channel', 'service')->update(['channel' => 'standard']);
            DB::statement("ALTER TABLE sales_orders MODIFY channel ENUM('standard','pos','b2b','ecommerce') NOT NULL DEFAULT 'standard'");

            DB::table('stock_ledgers')->where('type', 'service_issue')->delete();
            $this->setLedgerTypes(array_values(array_diff($this->ledgerTypes(), ['service_issue'])));
        }

        Schema::dropIfExists('service_job_items');
        Schema::dropIfExists('service_jobs');
        Schema::dropIfExists('service_contracts');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_service');
        });
    }

    private function isMysql(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * The ledger's current type values, read from the live column rather than
     * hard-coded: five earlier migrations have already widened this enum, and
     * restating the list here would silently drop whichever ones this file did
     * not know about.
     *
     * @return list<string>
     */
    private function ledgerTypes(): array
    {
        $column = DB::selectOne('SHOW COLUMNS FROM stock_ledgers WHERE Field = ?', ['type']);
        preg_match_all("/'([^']+)'/", $column->Type, $matches);

        return $matches[1];
    }

    /** @param  list<string>  $types */
    private function setLedgerTypes(array $types): void
    {
        $values = implode(',', array_map(static fn (string $t) => "'{$t}'", $types));
        DB::statement("ALTER TABLE stock_ledgers MODIFY COLUMN type ENUM({$values}) NOT NULL");
    }
};
