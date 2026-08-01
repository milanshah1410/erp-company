<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Healthcare plugin schema: the diagnostic lab and the hospital store.
 *
 * Two shapes the spine did not have.
 *
 *  1. **A lab order is a clinical document that happens to be billable.** It is
 *     registered against a patient, a sample is collected later, values are
 *     entered against reference ranges, a pathologist verifies them, and the
 *     report is released. Billing is one step inside that, so the order — not
 *     the invoice — is the record, and it holds its own status.
 *
 *     Three tables rather than two, because a bill line and a reported parameter
 *     are genuinely different things: "Lipid Profile" is one line on the bill and
 *     five rows on the report. Collapsing them would either bill five times or
 *     report once.
 *
 *  2. **A ward indent has no customer.** Stock leaves the central store against a
 *     department and nothing is sold. Routing that through the sales spine would
 *     invent a receivable that does not exist, so it posts straight to the stock
 *     ledger under its own movement type and the department is the analysis axis.
 *
 * `departments` (the HR master) is reused as the ward/cost-centre list rather
 * than a hospital_departments table beside it: the unit that employs the nurses
 * and the unit that consumes the gloves is one org unit, and two masters would
 * drift apart the first time a ward is renamed.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- 1. Lab test master ---------------------------------------------
        Schema::create('lab_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name');
            // Haematology, Biochemistry, Serology … how a report groups itself.
            $table->string('category', 80)->nullable();
            $table->string('sample_type', 40)->nullable();
            // "EDTA (lavender top)" — what the phlebotomist has to reach for.
            $table->string('container', 80)->nullable();
            $table->string('method', 120)->nullable();
            $table->string('unit', 40)->nullable();
            /**
             * The printed range ("13.0 – 17.0 g/dL", "Non-reactive"), kept as
             * text because plenty of tests do not report a number at all.
             */
            $table->string('reference_range', 160)->nullable();
            /**
             * The same range as bounds, when there is one. Only these two drive
             * the high/low flag — a test with no numeric range simply never
             * auto-flags, which is the correct behaviour for "Negative".
             */
            $table->decimal('ref_low', 15, 4)->nullable();
            $table->decimal('ref_high', 15, 4)->nullable();
            $table->decimal('price', 15, 2)->default(0);
            $table->decimal('tax_percent', 8, 2)->default(0);
            // Turnaround the counter promises, in hours.
            $table->unsignedSmallInteger('tat_hours')->nullable();
            /**
             * A panel (Lipid Profile, LFT) is billed as one test and reported as
             * many. Its components are listed in lab_test_components.
             */
            $table->boolean('is_panel')->default(false);
            $table->boolean('is_active')->default(true);
            /**
             * The is_service product this test bills as, created and kept in step
             * by LabTestService. Nullable because a test that has never been
             * billed does not need one yet.
             */
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'lab_tests_code_unique');
            $table->index(['tenant_id', 'category'], 'lab_tests_category_idx');
        });

        /**
         * Panel membership. Self-referential rather than a `parent_id` on
         * lab_tests: a component test (Cholesterol) belongs to several panels
         * and is also orderable on its own, so it cannot have one parent.
         */
        Schema::create('lab_test_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('panel_test_id')->constrained('lab_tests')->cascadeOnDelete();
            $table->foreignId('component_test_id')->constrained('lab_tests')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['panel_test_id', 'component_test_id'], 'lab_test_components_unique');
        });

        // --- 2. Lab orders ---------------------------------------------------
        Schema::create('lab_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('order_number', 40);

            /**
             * The patient is recorded on the order itself and the customer
             * account is optional. A walk-in gives a name and a phone and never
             * comes back; forcing a customer record for every one of them turns
             * the master into a phone book. When there *is* an account — a
             * corporate panel, a referring hospital — it is who gets billed.
             */
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('patient_name');
            $table->unsignedSmallInteger('patient_age')->nullable();
            $table->enum('patient_gender', ['male', 'female', 'other'])->nullable();
            $table->string('patient_phone', 20)->nullable();

            $table->string('referring_doctor')->nullable();
            $table->string('doctor_registration_no', 60)->nullable();
            // The slip the tests were asked for on, when the pharmacy module is
            // also in use. Nullable — a lab does not require one.
            $table->foreignId('prescription_id')->nullable()->constrained('prescriptions')->nullOnDelete();

            $table->date('order_date');
            $table->enum('priority', ['routine', 'urgent'])->default('routine');
            /**
             * The clinical lifecycle, which is the document's real state.
             * Whether it has been billed is a separate question answered by
             * invoice_id — a hospital panel is reported first and billed monthly,
             * a walk-in pays before the needle goes in, and a single status
             * cannot express both.
             */
            $table->enum('status', [
                'registered', 'collected', 'processing', 'reported', 'delivered', 'cancelled',
            ])->default('registered');

            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('item_discount_total', 15, 2)->default(0);
            $table->enum('discount_type', ['percent', 'flat'])->nullable();
            $table->decimal('discount_value', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('round_off', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);

            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->timestamp('collected_at')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            /**
             * Who signed the report off. A result is not a report until a
             * qualified person has verified it, and that person's name is on it.
             */
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'order_number'], 'lab_orders_number_unique');
            $table->index(['tenant_id', 'status', 'order_date'], 'lab_orders_worklist_idx');
        });

        /** What was ordered — one row per billable test or panel. */
        Schema::create('lab_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lab_order_id')->constrained('lab_orders')->cascadeOnDelete();
            $table->foreignId('lab_test_id')->constrained('lab_tests')->restrictOnDelete();
            /**
             * Snapshotted, like every other document line here: renaming a test
             * next year must not rewrite a report already handed to a patient.
             */
            $table->string('test_code', 40);
            $table->string('test_name');
            $table->string('sample_type', 40)->nullable();

            // No quantity column: a test is ordered once. Pricing treats it as 1.
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->enum('discount_type', ['percent', 'flat'])->nullable();
            $table->decimal('discount_value', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_percent', 8, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);

            // Per-test progress: one order can be part-collected and part-run.
            $table->enum('status', ['pending', 'collected', 'processing', 'completed'])->default('pending');
            $table->timestamp('collected_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'lab_order_id'], 'lab_order_items_order_idx');
        });

        /**
         * What gets reported — one row per parameter. A panel expands into its
         * components here while staying one line on the bill.
         */
        Schema::create('lab_order_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lab_order_id')->constrained('lab_orders')->cascadeOnDelete();
            $table->foreignId('lab_order_item_id')->constrained('lab_order_items')->cascadeOnDelete();
            $table->foreignId('lab_test_id')->constrained('lab_tests')->restrictOnDelete();

            $table->string('parameter');
            $table->string('unit', 40)->nullable();
            $table->string('reference_range', 160)->nullable();
            $table->decimal('ref_low', 15, 4)->nullable();
            $table->decimal('ref_high', 15, 4)->nullable();

            /**
             * The value as reported — text, because "Negative", "Trace" and
             * "1.2" are all valid results and forcing a decimal would lose the
             * first two.
             */
            $table->string('result_value', 160)->nullable();
            /**
             * The same value parsed to a number when it is one, so trending and
             * flagging have something to work with. Null for "Negative".
             */
            $table->decimal('numeric_value', 15, 4)->nullable();
            /**
             * Derived from the bounds on entry, but stored rather than computed
             * on read: a range revised next year must not silently re-flag a
             * report that was normal when it was signed.
             */
            $table->enum('flag', ['pending', 'normal', 'low', 'high', 'abnormal'])->default('pending');
            $table->string('remarks', 255)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'lab_order_id'], 'lab_order_results_order_idx');
        });

        // --- 3. Hospital store indents ---------------------------------------
        Schema::create('hospital_indents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('indent_number', 40);
            // The ward / cost centre asking. See the note at the top of the file
            // on why this is the HR department master.
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            // The store it is drawn from.
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->date('indent_date');
            $table->date('required_by')->nullable();
            $table->enum('priority', ['routine', 'urgent', 'emergency'])->default('routine');
            /**
             * `partially_issued` is a real resting state, not a transient one:
             * the store issues what it has and the ward chases the rest, often
             * for days. Without it the indent would have to be either open (and
             * look untouched) or closed (and lose the shortfall).
             */
            $table->enum('status', [
                'draft', 'submitted', 'approved', 'partially_issued', 'issued', 'rejected', 'cancelled',
            ])->default('draft');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'indent_number'], 'hospital_indents_number_unique');
            $table->index(['tenant_id', 'status', 'indent_date'], 'hospital_indents_board_idx');
            $table->index(['tenant_id', 'department_id'], 'hospital_indents_department_idx');
        });

        Schema::create('hospital_indent_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hospital_indent_id')->constrained('hospital_indents')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            // Which batch left the store — the whole point of tracking expiry is
            // being able to answer that after the fact.
            $table->foreignId('batch_id')->nullable()->constrained('batches')->nullOnDelete();

            $table->decimal('requested_quantity', 15, 3);
            /**
             * What the store agreed to. Defaults to the request on approval, but
             * kept separate because a store routinely cuts a ward's ask, and the
             * gap between asked and allowed is the number worth reporting.
             */
            $table->decimal('approved_quantity', 15, 3)->nullable();
            // Cumulative, so issuing in two goes is one line, not two.
            $table->decimal('issued_quantity', 15, 3)->default(0);
            // Snapshot at issue, so the department's consumption value does not
            // drift when the moving-average cost changes next week.
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('issue_value', 15, 2)->default(0);
            $table->string('remarks', 255)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'hospital_indent_id'], 'hospital_indent_items_indent_idx');
        });

        if ($this->isMysql()) {
            // Money: a lab bill is an ordinary sales order on its own channel, so
            // GST, receivables and every sales report see it without changes.
            $this->setSalesChannels([...$this->salesChannels(), 'lab']);

            // Stock: consumables leaving the central store for a ward. Read the
            // live enum rather than restating it — several migrations have
            // widened this column already.
            $this->setLedgerTypes([...$this->ledgerTypes(), 'hospital_issue']);
        }
    }

    public function down(): void
    {
        if ($this->isMysql()) {
            DB::table('stock_ledgers')->where('type', 'hospital_issue')->delete();
            $this->setLedgerTypes(array_values(array_diff($this->ledgerTypes(), ['hospital_issue'])));

            DB::table('sales_orders')->where('channel', 'lab')->update(['channel' => 'standard']);
            $this->setSalesChannels(array_values(array_diff($this->salesChannels(), ['lab'])));
        }

        Schema::dropIfExists('hospital_indent_items');
        Schema::dropIfExists('hospital_indents');
        Schema::dropIfExists('lab_order_results');
        Schema::dropIfExists('lab_order_items');
        Schema::dropIfExists('lab_orders');
        Schema::dropIfExists('lab_test_components');
        Schema::dropIfExists('lab_tests');
    }

    private function isMysql(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    /** @return list<string> */
    private function ledgerTypes(): array
    {
        return $this->enumValues('stock_ledgers', 'type');
    }

    /** @param  list<string>  $types */
    private function setLedgerTypes(array $types): void
    {
        $quoted = $this->quote(array_values(array_unique($types)));
        DB::statement("ALTER TABLE stock_ledgers MODIFY type ENUM({$quoted}) NOT NULL");
    }

    /** @return list<string> */
    private function salesChannels(): array
    {
        return $this->enumValues('sales_orders', 'channel');
    }

    /** @param  list<string>  $channels */
    private function setSalesChannels(array $channels): void
    {
        $quoted = $this->quote(array_values(array_unique($channels)));
        DB::statement("ALTER TABLE sales_orders MODIFY channel ENUM({$quoted}) NOT NULL DEFAULT 'standard'");
    }

    /** @return list<string> */
    private function enumValues(string $table, string $column): array
    {
        $definition = DB::selectOne("SHOW COLUMNS FROM {$table} WHERE Field = ?", [$column]);
        preg_match_all("/'([^']+)'/", $definition->Type, $matches);

        return $matches[1];
    }

    /** @param  list<string>  $values */
    private function quote(array $values): string
    {
        return implode(',', array_map(static fn (string $v) => "'".$v."'", $values));
    }
};
