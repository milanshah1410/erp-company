<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gift & Novelty plugin schema — the gift trade's three registers.
 *
 * A gift shop sells the same kind of object a general store sells; what makes it
 * a different business is everything that happens around the object. The goods
 * are ordinary stock and are left alone. These tables hold the three things a
 * general ERP has no reason to know:
 *
 *  - **gift_wrap_options / gift_wraps** — the wrap desk. Wrapping is not a note
 *     on an invoice: it is a small job with a queue, a promised time, material
 *     that leaves the shelf, and a rule about the price tag. The option is the
 *     master (what a shop offers and charges); the wrap is the parcel.
 *
 *     The *charge* deliberately does not live on the parcel's own bill. An option
 *     points at an `is_service` product, so wrapping is rung through the ordinary
 *     sales spine like any other line and needs no second money path — the same
 *     rule the rest of the ERP follows for labour. What the parcel stores is the
 *     charge that applied *at the time*, for the desk's own report.
 *
 *  - **gift_packs / gift_pack_items / gift_pack_builds** — the hamper. A combo
 *     pack is not a discount and not a menu deal: it is a physical thing
 *     assembled out of stock, and the moment a shop builds twenty Diwali hampers
 *     it holds twenty hampers and twenty fewer of everything inside them. So the
 *     definition is a bill of contents against a real hamper product, and a build
 *     is a stock movement in both directions (see GiftPackService).
 *
 *     Depleting the components at *sale* time — the Food & Beverage combo rule —
 *     was rejected here on purpose: hampers sit on a shelf wrapped, are counted
 *     at stock-take and are sold by scanning one barcode. Consuming their
 *     contents only when a hamper sells would make the shelf and the system
 *     disagree for the whole of the season that matters.
 *
 *  - **gift_occasions** — the reason a gift shop has repeat customers at all.
 *     Core already notifies staff of a customer's own birthday on the day
 *     (crm:notify-customer-occasions), which is the right feature for every other
 *     trade and useless for this one: the occasions a gift shop sells against
 *     belong to people who are not the customer ("my wife's birthday"), and a
 *     reminder that arrives on the morning of is a reminder that arrives after
 *     the gift was bought elsewhere. Hence a per-person register with lead time,
 *     a budget, and what was given last year.
 *
 * Two things a reader might expect here and will not find, both because they are
 * already core and a plugin must not ship a rival:
 *
 *  - **Gift vouchers** (`gift_vouchers`, 2026_08_03_110100). Sold at the counter
 *    and spent at the counter through PosController — which cannot be made to
 *    depend on a vertical that may not be installed.
 *  - **Product variants**. A gift shop's colours and sizes are ordinary
 *    `product_variants` rows, entered on the Products screen (or, for a shop that
 *    also runs the Retail vertical, through its size × colour matrix).
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- 1. What the shop offers to wrap things in -----------------------
        Schema::create('gift_wrap_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 120);
            /**
             * Kept because the desk works differently per kind, not for
             * reporting: a sheet of paper is folded round whatever is handed
             * over, a rigid box has to fit, and a basket is the hamper itself.
             */
            $table->enum('kind', ['paper', 'box', 'bag', 'basket', 'ribbon', 'card', 'other'])
                ->default('paper');
            $table->decimal('charge', 12, 2)->default(0);
            /**
             * The `is_service` product the charge is billed as. Nullable because
             * a shop that wraps free of charge should not be made to invent a
             * product first — but a chargeable option without one bills nothing,
             * which is why the request warns rather than the schema forbidding.
             */
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            /**
             * The material itself, and how much of it one parcel eats. Wrapping
             * paper, boxes and ribbon are bought by the hundred and are real
             * stock; a gift shop that never depletes them discovers at Diwali
             * that it ran out a week ago.
             */
            $table->foreignId('consumes_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->decimal('consume_quantity', 15, 3)->default(1);
            // What the desk quotes when the shop is busy: "ready in twenty
            // minutes". Nullable — most wraps are done while the customer waits.
            $table->unsignedSmallInteger('turnaround_minutes')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'gift_wrap_options_code_unique');
            $table->index(['tenant_id', 'is_active'], 'gift_wrap_options_active_idx');
        });

        // --- 2. Whose birthday is next ---------------------------------------
        // Before the parcels, because a parcel may point at the occasion it was
        // bought for — which is what turns a reminder into a sale the shop can
        // point at.
        Schema::create('gift_occasions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            /**
             * Whose occasion it is, and how they relate to the customer. Blank
             * means the customer themself. This pair is the reason the register
             * exists at all — core holds the customer's own dates and cannot hold
             * their wife's, which is what they are actually buying for.
             */
            $table->string('person_name', 160)->nullable();
            $table->string('relation', 60)->nullable();
            $table->enum('occasion', [
                'birthday', 'anniversary', 'wedding', 'festival',
                'graduation', 'housewarming', 'other',
            ])->default('birthday');
            // 'Diwali', 'Raksha Bandhan', 'Silver Jubilee' — the name of the
            // thing when the enum only says what sort of thing it is.
            $table->string('title', 120)->nullable();
            /**
             * The date it falls on. For a recurring occasion the year is only a
             * reference (the year they were born or married) and the next
             * occurrence is derived on read — storing a rolling "next date" would
             * need a nightly job to stay true, and would be wrong for a day
             * between the job failing and someone noticing.
             */
            $table->date('occasion_date');
            $table->boolean('is_recurring')->default(true);
            /**
             * Lead time, and the whole point of the register: a gift needs
             * choosing, and often ordering. A week is the trade default.
             */
            $table->unsignedSmallInteger('remind_days_before')->default(7);
            $table->decimal('budget', 12, 2)->nullable();
            $table->text('preferences')->nullable();
            // What was given last year, so the shop does not sell the same
            // perfume twice. The single most valuable line in the record.
            $table->string('last_gift', 255)->nullable();
            $table->date('last_gifted_on')->nullable();
            /**
             * Whether the customer is texted, as opposed to only the counter
             * being told. Per-occasion rather than per-customer: plenty of people
             * are happy to be reminded about their mother's birthday and would
             * rather the shop said nothing about their wedding anniversary.
             */
            $table->boolean('notify_customer')->default(true);
            $table->date('last_reminded_on')->nullable();
            /**
             * 'customer_master' marks a row lifted from the customer's own
             * date_of_birth / anniversary_date, so the import can be re-run
             * without doubling and a hand-edited row is never overwritten.
             */
            $table->enum('source', ['manual', 'customer_master'])->default('manual');
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'occasion_date'], 'gift_occasions_date_idx');
            $table->index(['tenant_id', 'customer_id'], 'gift_occasions_customer_idx');
            $table->index(['tenant_id', 'is_active', 'last_reminded_on'], 'gift_occasions_reminder_idx');
        });

        // --- 3. The parcels at the desk --------------------------------------
        Schema::create('gift_wraps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Quoted back at the counter when someone comes to collect.
            $table->string('wrap_number', 40);
            /**
             * Nullable and nullOnDelete, with the label and charge snapshotted
             * beside it: a parcel is a historical record, and retiring last
             * season's paper must not erase what was wrapped in it.
             */
            $table->foreignId('wrap_option_id')->nullable()->constrained('gift_wrap_options')->nullOnDelete();
            $table->string('wrap_label', 120)->nullable();
            $table->decimal('charge', 12, 2)->default(0);

            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            /**
             * The sale behind the parcel, if there is one yet. Both nullable and
             * both worth having: a parcel is often taken in against an order
             * that has not been billed, and is just as often wrapped after the
             * bill is already printed.
             */
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            // The occasion it was bought for, when the shop knows. This is what
            // turns a reminder into a sale the shop can point at.
            $table->foreignId('occasion_id')->nullable()->constrained('gift_occasions')->nullOnDelete();

            // Who it is for and who it is from — printed on the card, and the
            // only way the desk tells two identical parcels apart.
            $table->string('recipient_name', 160)->nullable();
            $table->string('recipient_phone', 20)->nullable();
            $table->string('sender_name', 160)->nullable();
            $table->text('message')->nullable();
            /**
             * The gift-receipt rule, and it defaults to *true* because that is
             * what the trade does: the person receiving a gift must not be able
             * to read what it cost. Consumed by whatever prints the parcel's
             * slip, so the flag has to travel with the parcel rather than being
             * a print-time afterthought.
             */
            $table->boolean('hide_prices')->default(true);

            $table->enum('status', ['pending', 'wrapped', 'collected', 'cancelled'])->default('pending');
            // When it was promised. The desk's whole queue is ordered by this,
            // and being late on it is the only failure this register has.
            $table->dateTime('promised_at')->nullable();
            $table->timestamp('wrapped_at')->nullable();
            $table->foreignId('wrapped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('collected_at')->nullable();

            // Where the paper came off. Nullable: resolved to the tenant's
            // default warehouse when the desk does not care, which is usually.
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            /**
             * When this parcel's material was depleted. The idempotency guard —
             * a parcel marked wrapped twice (a double tap, a re-open, a status
             * corrected back and forth) must not eat two sheets of paper.
             */
            $table->timestamp('material_posted_at')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'wrap_number'], 'gift_wraps_number_unique');
            $table->index(['tenant_id', 'status', 'promised_at'], 'gift_wraps_queue_idx');
            $table->index(['tenant_id', 'customer_id'], 'gift_wraps_customer_idx');
            $table->index(['tenant_id', 'invoice_id'], 'gift_wraps_invoice_idx');
        });

        // --- 4. The hamper: definition, contents, and each batch built -------
        Schema::create('gift_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 160);
            /**
             * The hamper's own product. Cascades: this row is that product's bill
             * of contents and means nothing without it. The selling price is the
             * product's — a pack price stored here would be a second answer to
             * "what does this cost?", and the till would use the other one.
             */
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            // 'diwali', 'wedding', 'corporate' — how a shop finds last year's
            // packs when the season comes round again.
            $table->string('occasion_tag', 60)->nullable();
            // Hampers are usually sold already wrapped, in the basket they were
            // built in.
            $table->foreignId('wrap_option_id')->nullable()->constrained('gift_wrap_options')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'gift_packs_code_unique');
            $table->index(['tenant_id', 'is_active'], 'gift_packs_active_idx');
            $table->index(['tenant_id', 'occasion_tag'], 'gift_packs_occasion_idx');
        });

        Schema::create('gift_pack_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pack_id')->constrained('gift_packs')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->decimal('quantity', 15, 3)->default(1);
            // Contents are shown to a customer in the order the shop lists them
            // ("the good chocolate first"), not alphabetically.
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'pack_id', 'sort_order'], 'gift_pack_items_pack_idx');
            $table->index(['tenant_id', 'product_id'], 'gift_pack_items_product_idx');
        });

        Schema::create('gift_pack_builds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('build_number', 40);
            $table->foreignId('pack_id')->constrained('gift_packs')->cascadeOnDelete();
            $table->decimal('quantity', 15, 3)->default(1);
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->date('build_date');
            /**
             * What one hamper entered stock at, worked out from what its contents
             * were costed out at, and then *stored*. Derived on read it would
             * change every time a component's average cost moved, and the margin
             * on a hamper sold in October would be rewritten in December.
             */
            $table->decimal('unit_cost', 15, 4)->default(0);
            /**
             * The idempotency guard, same shape as farm_production_logs: a build
             * posts contents out and hampers in, and a double tap must not do it
             * twice. Also what makes "unbuild" a first-class correction rather
             * than a database edit.
             */
            $table->timestamp('posted_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'build_number'], 'gift_pack_builds_number_unique');
            $table->index(['tenant_id', 'build_date'], 'gift_pack_builds_date_idx');
            $table->index(['tenant_id', 'pack_id'], 'gift_pack_builds_pack_idx');
        });

        if ($this->isMysql()) {
            /**
             * Hampers in, their contents out, and wrapping material out. Distinct
             * values rather than the generic 'production' / 'consumption' pair
             * already in the enum, for the reason Agriculture gave when it added
             * farm_produce: a shop that builds hampers and also runs a workshop
             * must be able to tell the two apart in one ledger, and a report that
             * cannot is a report nobody trusts.
             *
             * Read from the live column rather than restating the list — several
             * migrations have widened this enum and hard-coding it would silently
             * drop whatever this file does not know about.
             */
            $types = $this->ledgerTypes();
            $types[] = 'gift_pack_build';
            $types[] = 'gift_pack_consume';
            $types[] = 'gift_wrap_consume';
            $this->setLedgerTypes(array_values(array_unique($types)));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_pack_builds');
        Schema::dropIfExists('gift_pack_items');
        Schema::dropIfExists('gift_packs');
        Schema::dropIfExists('gift_wraps');
        Schema::dropIfExists('gift_occasions');
        Schema::dropIfExists('gift_wrap_options');

        if ($this->isMysql()) {
            $gone = ['gift_pack_build', 'gift_pack_consume', 'gift_wrap_consume'];
            DB::table('stock_ledgers')->whereIn('type', $gone)->delete();
            $this->setLedgerTypes(array_values(array_diff($this->ledgerTypes(), $gone)));
        }
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
