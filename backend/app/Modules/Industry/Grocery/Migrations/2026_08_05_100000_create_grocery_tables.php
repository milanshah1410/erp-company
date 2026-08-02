<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grocery plugin schema — two tables, and nothing added to core.
 *
 * A combo pack is a *product* with a statement of what it breaks into. Modelling
 * it that way is what keeps it out of everyone else's way: it has a price, a tax
 * rate, an HSN code and a barcode like anything else, so POS bills it, GST
 * reports it and a shop that never sells packs never notices one exists. Only
 * the stock pass at settle time has to know there are three items behind it.
 *
 * Two things a reader might expect here and will not find:
 *
 *  - **No supplier-credit table.** `suppliers.credit_limit`, `payment_terms_days`
 *    and `opening_balance` have been on the master since the beginning and were
 *    simply never read; the vertical adds the arithmetic, not a column. A plugin
 *    that invented a second limit would leave the one on the master master still
 *    lying there, wrong.
 *  - **No reuse of `products.is_combo`.** That flag belongs to the Food &
 *    Beverage plugin's migration, and a workspace that never bought F&B does not
 *    have it. Reading another vertical's column would make Grocery silently
 *    depend on Restaurant being installed — so the pack register is asked
 *    directly, in one keyed query per bill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grocery_combo_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            /**
             * The sellable pack. One definition per product, enforced: two
             * answers to "what does this break into?" would be ambiguous at
             * exactly the moment stock is being moved.
             */
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('name');
            /**
             * Sum of the components' own selling prices, snapshotted when the
             * pack is saved. Kept so the shelf label can say "₹1,240 worth for
             * ₹999" without re-pricing the components on every read — and so the
             * saving that was advertised stays the saving that was advertised
             * when a component's price moves.
             */
            $table->decimal('component_value', 15, 2)->default(0);
            /**
             * Snapshot of what the components cost the shop, for the same reason
             * and one more: on a two-percent trade, a pack whose discount has
             * quietly crossed the cost line is the single most expensive mistake
             * a kirana can make, and it must be visible without a report run.
             */
            $table->decimal('component_cost', 15, 2)->default(0);
            /**
             * A festival hamper is a pack for three weeks a year. Dates are
             * nullable — most packs run forever — and are read at the till, so a
             * lapsed Diwali hamper stops exploding rather than having to be
             * deleted and rebuilt next year.
             */
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'product_id'], 'grocery_combo_packs_product_unique');
            $table->index(['tenant_id', 'is_active'], 'grocery_combo_packs_active_idx');
        });

        Schema::create('grocery_combo_pack_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grocery_combo_pack_id')
                ->constrained('grocery_combo_packs')
                ->cascadeOnDelete();
            /**
             * A component is an ordinary saleable product, and the quantity is in
             * that product's own unit — "1" of a 1kg atta pack, "0.5" of a
             * kilogram of dry fruit sold loose. This is the quantity that leaves
             * stock, so it is stored at the same precision stock is.
             */
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->decimal('quantity', 15, 3)->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'grocery_combo_pack_id'], 'grocery_combo_pack_items_pack_idx');
            // The till resolves packs by component-free lookup, but the pack
            // screen and the "where is this item used?" question both read the
            // other way round.
            $table->index(['tenant_id', 'product_id'], 'grocery_combo_pack_items_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grocery_combo_pack_items');
        Schema::dropIfExists('grocery_combo_packs');
    }
};
