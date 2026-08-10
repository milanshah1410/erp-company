<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shop-staff sales commission (retail base §8b).
 *
 * Two incentive schemes already existed and neither covers this. Salesman
 * targets & incentives (`field_sales`) pay a *field* rep for meeting a monthly
 * number — one rate, one target, one person walking a beat. Tailor piece rates
 * pay for work done, not for selling. What was missing is the ordinary shop
 * case: the person behind the counter gets 1% of what they sell, 3% on the slow
 * brand the owner wants moved, and nothing on the loss leaders.
 *
 * That shape drives both tables:
 *
 *  1. `commission_rules` — what is promised, to whom, on what. Several rules can
 *     apply to one line, so they are *ranked*, and exactly one wins. Not summed:
 *     a shop that says "1% on everything, 3% on Brand X" means three, not four,
 *     and an engine that added them would quietly overpay for a year.
 *
 *  2. `commission_earnings` — what was actually earned, one row per (employee,
 *     invoice line, rule). Stored rather than derived on read, because the
 *     numbers behind it move: rules get edited, prices get corrected, and a
 *     payslip that changes retroactively is a payslip nobody trusts. The row
 *     snapshots the rate and the basis it was computed at.
 *
 * Who sold it comes from `sales_orders.created_by` → `employees.user_id`.
 * Deliberately the order's creator rather than a new column on the sale: at a
 * counter the person who rings the bill IS the person who sold it, and asking a
 * cashier to also pick a salesman from a dropdown is how a field goes unfilled.
 * A shop that needs to credit somebody else corrects the earning row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);

            /**
             * Who it applies to. `all` is the shop-wide default; `employee` and
             * `department` narrow it. A department rule is how "the electronics
             * floor gets 2%" is said once instead of eleven times.
             */
            $table->enum('applies_to', ['all', 'employee', 'department'])->default('all');
            $table->foreignId('employee_id')->nullable()->constrained('employees')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->cascadeOnDelete();

            /**
             * What it applies to. Also nullable-narrowing: null on all three
             * means "anything sold". A rule may name a category, a brand or a
             * single product — the three levels a shop actually thinks in.
             */
            $table->foreignId('category_id')->nullable()->constrained('categories')->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->cascadeOnDelete();

            /**
             * How it is worked out.
             *
             *  - `percent_of_sale`   — % of the line's net value. The common case.
             *  - `percent_of_margin` — % of (value − cost). What an owner *should*
             *    pay on, and now can: the cost is available from the stock ledger
             *    (the same source the profitability report uses). Falls back to
             *    zero commission where no cost was posted, which is honest — a
             *    line with no known cost has no known margin.
             *  - `flat_per_unit`     — a fixed amount per unit sold, for trades
             *    that pay "₹50 a pair" and do not think in percentages at all.
             */
            $table->enum('basis', ['percent_of_sale', 'percent_of_margin', 'flat_per_unit'])->default('percent_of_sale');
            $table->decimal('rate', 10, 4)->default(0);

            /**
             * Floors. A line below `min_line_amount` earns nothing (stops a shop
             * paying commission on a ₹5 packet of pins), and a bill below
             * `min_invoice_amount` earns nothing at all.
             */
            $table->decimal('min_line_amount', 15, 2)->default(0);
            $table->decimal('min_invoice_amount', 15, 2)->default(0);

            /**
             * Which rule wins when several match. Lower runs first and the first
             * match takes the line — so a specific product rule is given a low
             * number and the shop-wide default a high one. An explicit column
             * rather than "most specific wins": specificity is ambiguous the
             * moment a brand rule and an employee rule both match, and a shop
             * arguing with an inferred answer cannot fix it.
             */
            $table->unsignedSmallInteger('priority')->default(100);

            // Dated, because a scheme is announced for a season and withdrawn.
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The engine's read: active rules for a period, in priority order.
            $table->index(['tenant_id', 'is_active', 'priority'], 'commission_rules_active_idx');
        });

        Schema::create('commission_earnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            /**
             * nullOnDelete rather than cascade: a rule being retired or renamed
             * must not erase what was earned under it. The rate below is the
             * record that matters, and it is snapshotted here.
             */
            $table->foreignId('commission_rule_id')->nullable()->constrained('commission_rules')->nullOnDelete();

            // What it was earned on. The sales order is the spine; the invoice is
            // carried too because that is what the earning is reconciled against.
            $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('sales_item_id')->nullable()->constrained('sales_items')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            $table->date('earned_on');
            // The period it belongs to, so a payroll run reads one index rather
            // than a date range that has to agree with everyone's month boundary.
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');

            $table->decimal('quantity', 15, 3)->default(0);
            $table->decimal('sale_amount', 15, 2)->default(0);
            // Null where no cost was posted — distinct from a genuine zero margin.
            $table->decimal('cost_amount', 15, 2)->nullable();
            $table->decimal('margin_amount', 15, 2)->nullable();

            // Snapshot of how it was worked out, so the row explains itself long
            // after the rule behind it has been edited.
            $table->enum('basis', ['percent_of_sale', 'percent_of_margin', 'flat_per_unit']);
            $table->decimal('rate', 10, 4)->default(0);
            $table->decimal('commission_amount', 15, 2)->default(0);

            /**
             * pending → approved → paid. Approval is separate from payment
             * because they are different people's jobs on different days: a floor
             * manager agrees the numbers, and payroll settles them a fortnight
             * later. `reversed` is the fourth state and exists because sales come
             * back — see the note on SalesCommissionService::reverseFor().
             */
            $table->enum('status', ['pending', 'approved', 'paid', 'reversed'])->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->date('paid_on')->nullable();
            $table->string('payment_reference', 120)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            /**
             * One earning per line per rule. This is what makes re-running the
             * generator safe: a shop that generates the month twice gets the same
             * rows, not double pay. Nullable sales_item_id is fine here — MySQL
             * treats NULLs as distinct in a unique index, and the only rows with
             * a null line are manual adjustments, which are meant to be repeatable.
             */
            $table->unique(['tenant_id', 'sales_item_id', 'commission_rule_id', 'employee_id'], 'commission_earnings_line_unique');
            // The payout run: one employee, one month.
            $table->index(['tenant_id', 'employee_id', 'period_year', 'period_month'], 'commission_earnings_period_idx');
            $table->index(['tenant_id', 'status', 'earned_on'], 'commission_earnings_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_earnings');
        Schema::dropIfExists('commission_rules');
    }
};
