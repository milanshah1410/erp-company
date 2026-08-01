<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Manufacturing plugin schema: the shop floor.
 *
 * The ERP could already state what a product is made of (boms), plan a run
 * (manufacturing_orders), consume the material and receive the output. What it
 * could not state is everything that happens *between* those two moments, which
 * is where a factory actually spends its money:
 *
 *  1. machines — the plant a run is made on. A factory schedules by machine, not
 *     by product: "the 8-colour press is down" is the sentence that stops a
 *     printing press earning. Kept apart from the fixed-asset register (which
 *     answers "what do we own and what is it worth") and linked to it by
 *     asset_id, because depreciation and shop-floor scheduling are different
 *     questions about the same steel.
 *
 *  2. machine_downtimes — reason-coded stops. Utilisation is meaningless without
 *     them: a machine that ran four hours of a twelve-hour shift is either badly
 *     loaded or badly maintained, and only the reason code says which.
 *
 *  3. quality_inspections (+ defects) — the accept/reject decision, taken at a
 *     named stage. Incoming, in-process and final are recorded as one document
 *     rather than three tables because the act is identical; what changes is
 *     what is being judged. Defect lines carry the *reason* for rejection, which
 *     is the only part a factory can act on — a rejection percentage tells you
 *     that you have a problem, a Pareto of defect codes tells you where.
 *
 *  4. production_scraps — material and output that will never be sold, with its
 *     cost. Scrap is deliberately its own document rather than a stock
 *     adjustment: an adjustment says "the count was wrong", scrap says "we made
 *     it and lost it", and a factory that cannot tell those apart cannot tell a
 *     counting problem from a process one.
 *
 * Plus three additive columns on manufacturing_orders (machine, labour and
 * machine cost, rejected quantity) so the shop floor feeds the costing that
 * already exists rather than parallel to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- 1. Machine master ----------------------------------------------
        Schema::create('machines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name');
            /**
             * What kind of plant it is. Free-form rather than an enum: the ten
             * trades this vertical covers run looms, presses, moulding machines,
             * lathes, reactors and filling lines, and an enum would need editing
             * every time an eleventh trade arrives.
             */
            $table->string('machine_type', 60)->nullable();
            // Where it stands. Both nullable: a small unit has neither.
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            /**
             * The same steel on the fixed-asset register, when it is on it.
             * Nullable and never required: a leased machine is scheduled here
             * and owned by nobody, and forcing an asset row first would mean a
             * factory could not record its own plant until Accounts caught up.
             */
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->string('manufacturer')->nullable();
            $table->string('model_number', 100)->nullable();
            $table->string('serial_number', 100)->nullable();
            $table->date('installed_on')->nullable();

            // Rated output — pieces, metres or kilos per hour, in the unit the
            // shop talks in. Used to turn an order quantity into machine hours.
            $table->decimal('capacity_per_hour', 15, 3)->default(0);
            $table->string('capacity_uom', 20)->nullable();
            /**
             * The machine-hour rate: power, consumables, depreciation and
             * upkeep, per running hour. This is what makes a machine master
             * load-bearing rather than a list — it is what a run's machine cost
             * is computed from.
             */
            $table->decimal('hourly_cost', 15, 2)->default(0);
            $table->decimal('power_kw', 10, 2)->nullable();

            /**
             * Current state, kept on the master so the shop-floor list answers
             * "what can I load right now?" without scanning downtime rows.
             * `breakdown` and `maintenance` are separate: one is a failure to be
             * counted against the machine, the other is planned and is not.
             */
            $table->enum('status', ['running', 'idle', 'breakdown', 'maintenance', 'retired'])
                ->default('idle');
            $table->date('last_service_on')->nullable();
            $table->date('next_service_due')->nullable();

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // A machine code is how the floor refers to it on a docket; two
            // machines answering to "PRESS-2" is a scheduling accident waiting.
            $table->unique(['tenant_id', 'code'], 'machines_code_unique');
            $table->index(['tenant_id', 'status'], 'machines_status_idx');
        });

        // --- 2. Downtime ------------------------------------------------------
        Schema::create('machine_downtimes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->constrained('machines')->cascadeOnDelete();
            // The run that was interrupted, when there was one. A breakdown
            // during a changeover belongs to no order.
            $table->foreignId('manufacturing_order_id')->nullable()
                ->constrained('manufacturing_orders')->nullOnDelete();
            $table->timestamp('started_at');
            // Null while the machine is still down — that is how "currently
            // down" is known without a second flag to keep in step.
            $table->timestamp('ended_at')->nullable();
            /**
             * Duration in minutes, stamped when the stop is closed. Stored
             * rather than derived so utilisation reports sum a column instead of
             * computing across two timestamps per row.
             */
            $table->unsignedInteger('minutes')->default(0);
            $table->enum('reason', [
                'breakdown', 'maintenance', 'power_failure', 'no_material',
                'no_operator', 'changeover', 'quality_hold', 'other',
            ])->default('breakdown');
            $table->text('remarks')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'machine_id', 'started_at'], 'machine_downtimes_machine_idx');
        });

        // --- 3. Quality inspection -------------------------------------------
        Schema::create('quality_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('inspection_number', 40);
            /**
             * Where in the flow the judgement was made. The same document shape
             * serves all four because the act — offer a lot, sample it, accept
             * or reject — does not change; only what is in front of the
             * inspector does.
             */
            $table->enum('stage', ['incoming', 'in_process', 'final', 'pre_dispatch'])
                ->default('final');
            $table->date('inspected_on');

            /**
             * What was inspected. Two concrete links rather than a polymorphic
             * pair: incoming QC judges a goods receipt, everything else judges a
             * production run, and those two are the joins every report needs.
             * Job-work quality is booked against the order the work belongs to.
             */
            $table->foreignId('manufacturing_order_id')->nullable()
                ->constrained('manufacturing_orders')->nullOnDelete();
            $table->foreignId('goods_receipt_id')->nullable()
                ->constrained('goods_receipts')->nullOnDelete();

            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            // Chemical, food and pharma inspect a batch, not a product.
            $table->foreignId('batch_id')->nullable()->constrained('batches')->nullOnDelete();

            $table->decimal('lot_quantity', 15, 3)->default(0);
            // How many were actually checked. A lot of 5,000 judged on 50 is a
            // different statement from one judged on all 5,000, and the sample
            // size is the difference.
            $table->decimal('sample_size', 15, 3)->default(0);
            $table->decimal('accepted_quantity', 15, 3)->default(0);
            $table->decimal('rejected_quantity', 15, 3)->default(0);
            /**
             * Kept apart from rejected: rework goes back to the floor and may
             * still be sold, rejection is a loss. Adding them together would
             * overstate scrap and understate the labour spent fixing things.
             */
            $table->decimal('rework_quantity', 15, 3)->default(0);

            /**
             * `conditional` is a real outcome, not a fudge: material accepted
             * under deviation (off-shade fabric taken at a discount, a batch
             * released against a customer waiver) is neither a pass nor a
             * rejection, and recording it as either loses the deviation.
             */
            $table->enum('result', ['pending', 'passed', 'failed', 'conditional'])->default('pending');
            $table->foreignId('inspector_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'inspection_number'], 'quality_inspections_number_unique');
            $table->index(['tenant_id', 'result'], 'quality_inspections_result_idx');
            $table->index(['tenant_id', 'manufacturing_order_id'], 'quality_inspections_mo_idx');
        });

        Schema::create('quality_inspection_defects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quality_inspection_id')->constrained('quality_inspections')->cascadeOnDelete();
            // The defect itself, in the shop's own vocabulary — "broken stitch",
            // "short shot", "shade variation", "under weight".
            $table->string('defect', 120);
            $table->decimal('quantity', 15, 3)->default(0);
            /**
             * Severity drives what happens next, and is why a count alone is not
             * enough: fifty minor defects can ship, one critical cannot.
             */
            $table->enum('severity', ['minor', 'major', 'critical'])->default('minor');
            $table->string('remarks')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'quality_inspection_id'], 'qi_defects_inspection_idx');
        });

        // --- 4. Scrap / rejection --------------------------------------------
        Schema::create('production_scraps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('scrap_number', 40);
            $table->date('scrap_date');

            $table->foreignId('manufacturing_order_id')->nullable()
                ->constrained('manufacturing_orders')->nullOnDelete();
            // The inspection that condemned it, when scrap came out of QC.
            $table->foreignId('quality_inspection_id')->nullable()
                ->constrained('quality_inspections')->nullOnDelete();

            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->decimal('quantity', 15, 3);

            /**
             * Which side of the run was lost, and the reason the two cannot be
             * one field: raw material scrapped *was* in stock and must leave it,
             * while output rejected on the line was never received into stock at
             * all — posting that out would drive the balance negative for goods
             * that never arrived. `affects_stock` below is defaulted from this.
             */
            $table->enum('scrap_type', ['material', 'finished'])->default('material');
            $table->enum('reason', [
                'rejection', 'setup_waste', 'machine_defect', 'material_defect',
                'operator_error', 'trial_run', 'expiry', 'other',
            ])->default('rejection');

            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('total_value', 15, 2)->default(0);
            /**
             * What the scrap is still worth. Regrind, cut-offs, metal turnings
             * and paper trim are sold by the kilo in most of these trades, so
             * the net loss is value minus recovery — and a factory that reports
             * only the gross figure overstates what it lost.
             */
            $table->decimal('recoverable_value', 15, 2)->default(0);

            // Whether this scrap took stock out. Stamped, not inferred, because
            // reversal has to know exactly what was posted.
            $table->boolean('affects_stock')->default(true);
            $table->boolean('is_posted')->default(false);
            $table->timestamp('posted_at')->nullable();

            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'scrap_number'], 'production_scraps_number_unique');
            $table->index(['tenant_id', 'scrap_date'], 'production_scraps_date_idx');
            $table->index(['tenant_id', 'manufacturing_order_id'], 'production_scraps_mo_idx');
        });

        // --- 5. The run learns about the floor --------------------------------
        Schema::table('manufacturing_orders', function (Blueprint $table) {
            // Which machine the run is loaded on. Nullable: a hand-finishing
            // order runs on nobody's press.
            $table->foreignId('machine_id')->nullable()->after('warehouse_id')
                ->constrained('machines')->nullOnDelete();
            /**
             * Two more cost buckets beside the existing six (see
             * ManufacturingOrder::COST_BUCKETS). `tailor_cost` covered garment
             * piece-work only; a moulding shop pays shift wages and a machine
             * rate, and had nowhere to put either — so the finished cost of
             * anything that is not a garment was understated by exactly the two
             * biggest numbers on a factory floor.
             */
            $table->decimal('labour_cost', 15, 2)->default(0)->after('tailor_cost');
            $table->decimal('machine_cost', 15, 2)->default(0)->after('labour_cost');
            // Rolled up from passed/failed inspections against this order.
            $table->decimal('quantity_rejected', 15, 3)->default(0)->after('quantity_produced');
        });

        if ($this->isMysql()) {
            // Stock leaving as scrap. Read the live column rather than restating
            // the list: several migrations have widened this enum already, and
            // hard-coding would drop the values this file does not know about.
            $types = $this->ledgerTypes();
            $types[] = 'scrap';
            $this->setLedgerTypes(array_values(array_unique($types)));
        }
    }

    public function down(): void
    {
        if ($this->isMysql()) {
            DB::table('stock_ledgers')->where('type', 'scrap')->delete();
            $this->setLedgerTypes(array_values(array_diff($this->ledgerTypes(), ['scrap'])));
        }

        Schema::table('manufacturing_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('machine_id');
            $table->dropColumn(['labour_cost', 'machine_cost', 'quantity_rejected']);
        });

        Schema::dropIfExists('production_scraps');
        Schema::dropIfExists('quality_inspection_defects');
        Schema::dropIfExists('quality_inspections');
        Schema::dropIfExists('machine_downtimes');
        Schema::dropIfExists('machines');
    }

    private function isMysql(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * The ledger's current type values, read from the live column.
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
        $quoted = implode(',', array_map(static fn (string $t) => "'".$t."'", $types));
        DB::statement("ALTER TABLE stock_ledgers MODIFY type ENUM({$quoted}) NOT NULL");
    }
};
