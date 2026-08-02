<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sports & Fitness plugin schema: the hire fleet, and what happens to it.
 *
 * `.claude/shops-modules.md` §"🏏 12. Sports Shop": Brand, Size, Color, Warranty,
 * Barcode, Repair, Rental (Optional).
 *
 * Six of those seven already exist and are deliberately not rebuilt here — brands
 * are core, size and colour are the Retail plugin's variant matrix over ordinary
 * `product_variants`, warranty is core's `product_warranties`, barcode is
 * `inventory`/`devices`, and a repair is a `ServiceJob` (see SportsPlugin for the
 * full reasoning). What is left is the bullet marked optional, and it is the only
 * one no other vertical in this product does at all: the shop hires its goods out
 * and expects them back.
 *
 * That single fact breaks an assumption the whole ERP is built on. Everywhere
 * else, an article leaves the shop once and the transaction is over. A hire bat
 * leaves eighty times, comes back seventy-nine, earns money by the day rather
 * than by the piece, and is worth less each trip. None of that is expressible as
 * a sale, which is why this is three tables and not a flag on an invoice.
 *
 * Three groups of change:
 *
 *  1. sports_rental_assets — the fleet. Individually identified kit that is hired
 *     rather than sold, held *outside* inventory on purpose (see the note on the
 *     table).
 *
 *  2. sports_rentals + sports_rental_items — the hire contract and its lines. A
 *     booking, an issue, a return that is routinely partial, and a settlement
 *     where the deposit meets the bill.
 *
 *  3. The seams into core: a `rental` sales channel so hire income leaves through
 *     the ordinary spine, a `deposit` payment mode so the cash book can see a bill
 *     settled out of money the shop was already holding, and `sports_asset_id` on
 *     `service_jobs` so a bike that comes back with a bent wheel goes to the same
 *     bench a customer's bike would.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- 1. The hire fleet -------------------------------------------------
        /**
         * A rentable asset is NOT stock, and this is the most consequential
         * decision in the vertical.
         *
         * The temptation is to hire out ordinary products and move stock on issue
         * and return. It does not survive contact with the trade. One bicycle
         * hired sixty times would post a hundred and twenty stock movements, the
         * valuation would swing between zero and cost every weekend, the stock
         * ledger would become unreadable, and the one question the shop actually
         * asks — "is bike 7 free on Sunday?" — would still be unanswerable,
         * because a quantity of one tells you nothing about *which* one or *when*.
         *
         * So the fleet is a register of individually identified things, each with
         * its own rates, its own condition and its own history. `product_id` links
         * it back to the catalogue when the shop also sells the same article (a
         * demo racquet on the hire rack is the same model as the one on the wall)
         * — for the name, the brand and the photo, never for stock.
         */
        Schema::create('sports_rental_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // What is painted on the frame or taped to the handle. Short, because
            // it is read out over a counter and written on a paper tag.
            $table->string('asset_code', 40);
            $table->string('name', 120);

            /**
             * The catalogue article this is a unit of, when there is one. Nullable
             * because a great deal of hire kit was never a sale line — a shop buys
             * six hire bikes it has no intention of selling — and forcing a
             * product row for each would put phantom articles in the catalogue and
             * on every price list.
             *
             * The variant carries size and colour, which is exactly what a hire
             * fleet is asked about: "have you got a 26-inch?". That is the Retail
             * plugin's size × colour matrix doing its job here for free.
             */
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();

            // Where it physically lives. A two-branch shop hires from the branch
            // that holds it, and an availability answer that ignores this sends
            // a customer across town.
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();

            $table->string('serial_no', 80)->nullable();
            // Free text rather than a register: "Cricket", "Cycles", "Camping".
            // A taxonomy nobody asked for is a screen nobody fills in, and the
            // shop that wants one has Custom Fields.
            $table->string('category', 60)->nullable();

            /**
             * The rate card, one column per basis, every one nullable.
             *
             * Four columns rather than one rate plus a unit, because the same
             * article is genuinely hired on different bases by different customers
             * — a bike is ₹80 an hour to a tourist and ₹1,200 a month to a
             * student — and a shop that can only publish one of those prices ends
             * up keeping the other on a card by the till. A null means "not
             * offered on that basis", which is a real answer and the reason the
             * contract refuses a basis the asset has no rate for.
             */
            $table->decimal('rate_hourly', 12, 2)->nullable();
            $table->decimal('rate_daily', 12, 2)->nullable();
            $table->decimal('rate_weekly', 12, 2)->nullable();
            $table->decimal('rate_monthly', 12, 2)->nullable();

            /**
             * What the shop holds against the thing coming back. Not revenue and
             * never treated as such — see the settlement note on sports_rentals.
             */
            $table->decimal('deposit_amount', 12, 2)->default(0);

            /**
             * The overdue rate, as a percentage of the ordinary one. 100 means a
             * late day costs a day; 150 is the penal rate a shop charges when the
             * kit was promised to somebody else that afternoon.
             *
             * A percentage rather than a second rate card so it survives a
             * repricing: put the daily rate up and the late rate follows, which is
             * what every shop means by "one and a half times".
             */
            $table->unsignedSmallInteger('late_fee_percent')->default(100);

            /**
             * Whether the counter must see identification before this leaves the
             * shop. A switch and not a policy: nobody photographs a driving
             * licence to hand over a skipping rope, and everybody does to hand
             * over a ₹40,000 bicycle. Off by default, so a shop turns it on for
             * the kit that warrants it rather than having it turned on for
             * everything and learning to click past it.
             */
            $table->boolean('requires_id_proof')->default(false);

            /**
             * Condition, and the reason a hire fleet is a depreciating thing
             * rather than a catalogue: it gets worse. Recorded on every return, so
             * the register is also the evidence behind the next damage charge —
             * "it went out good and came back worn" is only arguable if somebody
             * wrote down that it went out good.
             */
            $table->enum('condition', ['new', 'good', 'fair', 'worn', 'damaged'])->default('good');

            /**
             * Stored status carries only what cannot be worked out: withdrawn for
             * repair, or written off. **On hire is derived**, never stored — from
             * an unreturned line on an open contract — for the same reason
             * certificate expiry is derived elsewhere in this codebase. A stored
             * flag says "available" about a bike that has been in a customer's van
             * since Friday the moment anything fails to run, and the one question
             * this register exists to answer is exactly that one.
             */
            $table->enum('status', ['available', 'maintenance', 'retired'])->default('available');

            // What it cost and when it was bought — the two numbers a utilisation
            // report needs to say whether a hire asset has paid for itself.
            $table->decimal('purchase_cost', 12, 2)->nullable();
            $table->date('acquired_on')->nullable();

            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'asset_code'], 'sports_assets_code_unique');
            $table->index(['tenant_id', 'status', 'is_active'], 'sports_assets_status_idx');
            $table->index(['tenant_id', 'category'], 'sports_assets_category_idx');
        });

        // --- 2. The hire contract ---------------------------------------------
        /**
         * One document for the booking and the hire, not two.
         *
         * A shop takes six bikes for Sunday on Tuesday afternoon and hands them
         * over on Sunday morning. Those are two states of one agreement, and
         * splitting them into a reservation document and a hire document produces
         * the classic double-count — kit reserved on one and issued on the other,
         * free according to neither. So `booked` and `out` are statuses on the
         * same row, and availability reads both.
         */
        Schema::create('sports_rentals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('rental_number', 40);

            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();

            /**
             * booked  — held for a window, nothing handed over
             * out     — with the customer, in whole or in part
             * returned— everything back, not yet settled
             * closed  — billed and the deposit dealt with
             * cancelled — a booking that did not happen
             *
             * There is no 'overdue' here on purpose. Overdue is `out` with the due
             * time behind us, which is derivable at any instant and therefore
             * cannot be stale; a stored flag would depend on a sweep running, and
             * the overdue board is the screen this module is opened for.
             */
            $table->enum('status', ['booked', 'out', 'returned', 'closed', 'cancelled'])->default('booked');

            /**
             * The basis the whole contract is priced on. One basis per contract
             * rather than per line, because a bill that mixes hourly and weekly
             * lines cannot be explained across a counter, and the customer who
             * asks "so what am I paying a day?" deserves an answer. A shop hiring
             * one thing by the hour and another by the month writes two contracts,
             * which is what it would tell you it does anyway.
             */
            $table->enum('rate_basis', ['hourly', 'daily', 'weekly', 'monthly'])->default('daily');

            // The agreed window. `booked_from` is when the customer wants it;
            // `issued_at` is when it actually left, which is routinely not the
            // same and is what the hire is charged from.
            $table->dateTime('booked_from')->nullable();
            $table->dateTime('due_at');
            $table->dateTime('issued_at')->nullable();
            $table->dateTime('returned_at')->nullable();

            /**
             * How late is late. Zero by default, because a shop that wants a
             * grace period should have to say so — but it is here rather than
             * hard-coded because charging a whole extra day for a twenty-minute
             * overrun is the single commonest argument at a hire counter, and the
             * shop should be able to settle it in advance instead of at the till.
             */
            $table->unsignedSmallInteger('grace_hours')->default(0);

            /**
             * Who took it. Held on the contract rather than on the customer,
             * because the licence shown for a bike hire is evidence about *this*
             * hire — a customer master is not a place to accumulate photocopies of
             * identity documents, and the counter needs the number that was
             * actually checked on the day.
             */
            $table->string('id_proof_type', 40)->nullable();
            $table->string('id_proof_number', 60)->nullable();

            /**
             * The deposit, and the reason it is here and not in the sales figures.
             *
             * A security deposit is the customer's money that the shop is holding.
             * It is not a supply, it is not turnover, and running it through the
             * sales spine would inflate revenue, put GST on a sum no tax is due on,
             * and make every hire look like two sales. So the deposit is carried
             * on this document, and only the part that is *kept* — applied against
             * hire, late fees or damage — ever becomes money the shop earned, at
             * which point it appears as a tender against the invoice on the
             * `deposit` payment mode.
             *
             * There is deliberately no `deposit_forfeited` column. Keeping a
             * deposit is never a thing in itself: it is always keeping it *for*
             * something, and that something is a damage charge on the line. A shop
             * that keeps ₹2,000 for a lost bat raises a ₹2,000 damage charge and
             * applies the deposit to it — which leaves a bill that says what
             * happened, instead of a forfeiture that says only that somebody was
             * annoyed.
             */
            $table->decimal('deposit_amount', 12, 2)->default(0);
            $table->decimal('deposit_applied', 12, 2)->default(0);
            $table->decimal('deposit_refunded', 12, 2)->default(0);

            // The three charges, totalled from the lines at settlement.
            $table->decimal('hire_total', 12, 2)->default(0);
            $table->decimal('late_total', 12, 2)->default(0);
            $table->decimal('damage_total', 12, 2)->default(0);

            // Header discount, on the same rules every other document here uses —
            // a club rate or a regular's ten percent.
            $table->enum('discount_type', ['percent', 'flat'])->nullable();
            $table->decimal('discount_value', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('round_off', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            /**
             * Stamped when the contract is billed, and the idempotency guard that
             * stops a double-click settling a hire twice.
             */
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'rental_number'], 'sports_rentals_number_unique');
            // The overdue board and the day's returns both read this pair.
            $table->index(['tenant_id', 'status', 'due_at'], 'sports_rentals_due_idx');
            $table->index(['tenant_id', 'customer_id'], 'sports_rentals_customer_idx');
        });

        /**
         * One line per asset. Never a quantity — the whole point of the fleet is
         * that four bikes are four identified bikes, and "qty 4" is precisely the
         * answer that cannot tell you which two came back.
         */
        Schema::create('sports_rental_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_id')->constrained('sports_rentals')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('sports_rental_assets')->cascadeOnDelete();

            /**
             * Snapshots, all four of them, and for one reason: the rate card moves
             * and the contract must not. A season rate rise applied on Monday must
             * not reprice a bike that went out on Friday, and a deposit taken in
             * cash at the counter has to be refundable at the sum that was
             * actually taken.
             */
            $table->decimal('rate', 12, 2)->default(0);
            $table->decimal('deposit', 12, 2)->default(0);
            $table->unsignedSmallInteger('late_fee_percent')->default(100);

            /**
             * Per-line return, because a partial return is the normal case and not
             * an edge one. Six bikes go out for a weekend; four come back Sunday
             * evening and two on Monday morning because somebody stayed over. A
             * return recorded only on the header would either charge the four for
             * Monday or let the two off — and would leave four bikes unavailable
             * for Monday's customer.
             */
            $table->dateTime('returned_at')->nullable();

            $table->enum('condition_out', ['new', 'good', 'fair', 'worn', 'damaged'])->default('good');
            /**
             * `lost` exists only on the way back in, for the obvious reason. It
             * retires the asset — the fleet register must stop offering a bat that
             * is in somebody's car boot in another state.
             */
            $table->enum('condition_in', ['new', 'good', 'fair', 'worn', 'damaged', 'lost'])->nullable();

            // What was actually charged, worked out at return and then frozen.
            $table->decimal('hire_units', 10, 2)->default(0);
            $table->decimal('hire_charge', 12, 2)->default(0);
            $table->decimal('late_units', 10, 2)->default(0);
            $table->decimal('late_charge', 12, 2)->default(0);
            $table->decimal('damage_charge', 12, 2)->default(0);
            $table->string('damage_note', 255)->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'asset_id', 'returned_at'], 'sports_rental_items_asset_idx');
            $table->index(['tenant_id', 'rental_id'], 'sports_rental_items_rental_idx');
        });

        // --- 3. The seams into core --------------------------------------------
        /**
         * A hire asset that comes back broken goes to the same bench a customer's
         * racquet goes to. The job card is a core `ServiceJob` — this is one
         * nullable column, not a second repair register, on exactly the precedent
         * Automobile set with `vehicle_id` and Electronics with `device_unit_id`.
         *
         * Prefixed `sports_` because three verticals already hang relations off
         * this table, and an unqualified `asset_id` would collide with the next
         * one to want it.
         */
        Schema::table('service_jobs', function (Blueprint $table) {
            $table->foreignId('sports_asset_id')->nullable()->after('item_serial')
                ->constrained('sports_rental_assets')->nullOnDelete();
        });

        if ($this->isMysql()) {
            /**
             * Hire income leaves through the ordinary sales spine, on a channel of
             * its own so that a shop can tell counter sales from hire without
             * reading the line detail. Additive: the enum is read and appended to,
             * so this migration does not have to know which verticals ran before
             * it.
             */
            $this->setSalesChannels([...$this->salesChannels(), 'rental']);

            /**
             * Settling a bill out of a deposit the shop is already holding is a
             * tender, exactly as old gold, a gift voucher, redeemed points or a
             * part-exchange are. Its own mode rather than `cash`, because the
             * money never went through the drawer today — it went through it three
             * weekends ago — and a cash-up that counts it as cash will not
             * reconcile.
             */
            $this->setPaymentModes([...$this->paymentModes(), 'deposit']);
        }
    }

    public function down(): void
    {
        if ($this->isMysql()) {
            // Land the widened values somewhere the narrower enum can hold them
            // before the ALTER, or MySQL blanks the rows without saying so.
            DB::table('payments')->where('mode', 'deposit')->update(['mode' => 'cash']);
            $this->setPaymentModes(array_values(array_diff($this->paymentModes(), ['deposit'])));

            DB::table('sales_orders')->where('channel', 'rental')->update(['channel' => 'standard']);
            $this->setSalesChannels(array_values(array_diff($this->salesChannels(), ['rental'])));
        }

        Schema::table('service_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sports_asset_id');
        });

        Schema::dropIfExists('sports_rental_items');
        Schema::dropIfExists('sports_rentals');
        Schema::dropIfExists('sports_rental_assets');
    }

    private function isMysql(): bool
    {
        return DB::getDriverName() === 'mysql';
    }

    /** @return list<string> */
    private function salesChannels(): array
    {
        return $this->enumValues('sales_orders', 'channel');
    }

    /** @param  list<string>  $channels */
    private function setSalesChannels(array $channels): void
    {
        DB::statement(
            'ALTER TABLE sales_orders MODIFY channel ENUM('.$this->quote($channels).") NOT NULL DEFAULT 'standard'",
        );
    }

    /** @return list<string> */
    private function paymentModes(): array
    {
        return $this->enumValues('payments', 'mode');
    }

    /** @param  list<string>  $modes */
    private function setPaymentModes(array $modes): void
    {
        DB::statement(
            'ALTER TABLE payments MODIFY mode ENUM('.$this->quote($modes).") NOT NULL DEFAULT 'cash'",
        );
    }

    /** @return list<string> */
    private function enumValues(string $table, string $column): array
    {
        $definition = DB::selectOne("SHOW COLUMNS FROM {$table} WHERE Field = ?", [$column]);
        preg_match_all("/'([^']+)'/", $definition->Type, $matches);

        return $matches[1] ?? [];
    }

    /** @param  list<string>  $values */
    private function quote(array $values): string
    {
        return implode(',', array_map(
            static fn (string $v) => "'".addslashes($v)."'",
            array_values(array_unique($values)),
        ));
    }
};
