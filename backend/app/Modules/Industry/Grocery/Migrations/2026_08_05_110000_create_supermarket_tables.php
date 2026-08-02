<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grocery plugin — the supermarket trade.
 *
 * In this vertical rather than Retail because a supermarket *is* a grocery shop:
 * it buys the same goods from the same distributors on the same credit and sells
 * them at the same margin. It differs from the kirana next door only in scale,
 * and everything the kirana trade already ships — combo packs, supplier credit,
 * batch and expiry — a supermarket wants unchanged.
 *
 * A self-service store differs from a counter shop in one structural way: the
 * customer, not the shopkeeper, picks the goods off a shelf. Everything peculiar
 * to the trade follows from that — the shelf has to be laid out and kept filled,
 * and the lane the customer walks up to may have nobody standing behind it.
 *
 * Deliberately small, because most of what the trade needs already exists:
 * barcodes, batches and expiry are Inventory, stock transfers are Inventory,
 * coupons, gift cards and membership are Loyalty & Offers, and the multi-counter
 * till register is core POS (see the pos_counters migration for why it is not
 * here). What was actually missing is three things:
 *
 *  1. **Shelf attributes on `warehouse_locations`.** A shelf is a location and
 *     the location tree already has a `shelf` type — inventing a parallel
 *     `store_shelves` table would have given a supermarket two places to say
 *     where something is, which reconcile differently the first time anyone
 *     moves a rack. The extension follows the precedent Food & Beverage set with
 *     `is_freezer` / `target_temp_c`: nullable columns on the core table, added
 *     by the plugin that reads them.
 *
 *     `aisle` / `bay` / `shelf_level` are the shopper-facing address ("Aisle 7,
 *     bay 3, eye level"), which is a different thing from the warehouse code:
 *     the code identifies the location to the system, the address is what gets
 *     printed on a shelf-edge label and told to a customer who asked where the
 *     rice is.
 *
 *  2. **`shelf_placements` — the planogram.** Which product belongs on which
 *     shelf, how many facings it gets and the fill range it should be kept
 *     within. This genuinely has nowhere to live: it is not stock (it says what
 *     *should* be there, not what is), not a product attribute (the same product
 *     sits in three places in a big store) and not a location attribute.
 *
 *  3. **`self_checkout_carts` / `_items` — the unattended lane.** A staging
 *     basket, not a sale. The customer scans into it, an attendant clears
 *     whatever needs clearing, and the *till* then rings it up through the
 *     ordinary POS checkout. That split is the same one the trial room makes and
 *     for the same reason: one path to a sale, or the two drift.
 *
 *  4. **`products.is_age_restricted`** — the only reason a supermarket basket
 *     stops by itself. Nullable-by-default boolean on the core table, so
 *     unregistering the vertical leaves an unused column and nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- 1. Shelves are locations ----------------------------------------
        Schema::table('warehouse_locations', function (Blueprint $table) {
            // Marks the ones a customer picks from, as opposed to the backroom
            // racks the same tree holds. Replenishment is exactly the movement
            // from the latter to the former, so the flag is what makes the two
            // ends of it distinguishable.
            $table->boolean('is_shelf')->default(false)->after('type');
            $table->string('aisle', 30)->nullable()->after('is_shelf');
            $table->string('bay', 30)->nullable()->after('aisle');
            // "1" is the bottom. Kept as a number rather than "eye level" /
            // "stoop" because the merchandising rule a buyer negotiates is
            // stated in levels, and the words differ by chain.
            $table->unsignedTinyInteger('shelf_level')->nullable()->after('bay');
            $table->index(['tenant_id', 'is_shelf'], 'warehouse_locations_shelf_idx');
        });

        // --- 2. The planogram --------------------------------------------------
        Schema::create('shelf_placements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('warehouse_locations')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            // How many units of the pack face the shopper. The merchandising
            // number: it decides how much space the line gets and, with the
            // depth of the shelf, roughly how much fits.
            $table->unsignedSmallInteger('facings')->default(1);
            /**
             * The fill range. `min_qty` is the point at which the shelf counts as
             * gapped and lands on the replenishment list; `max_qty` is what a
             * full shelf holds, and is what the suggested top-up quantity is
             * worked back from. Both in the product's selling unit.
             */
            $table->decimal('min_qty', 12, 3)->default(0);
            $table->decimal('max_qty', 12, 3)->default(0);
            // Left-to-right order along the bay, for the shelf-edge label run.
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            /**
             * One placement per product per shelf. The same product may — and in
             * a big store routinely does — appear on several shelves, which is
             * why the uniqueness is on the pair and not on the product.
             *
             * MySQL treats NULLs as distinct, so this index does not stop two
             * variant-less rows for one product on one shelf; ShelfPlacementRequest
             * closes that with an explicit null-aware check.
             */
            $table->unique(['tenant_id', 'location_id', 'product_id', 'variant_id'], 'shelf_placements_unique');
            $table->index(['tenant_id', 'product_id'], 'shelf_placements_product_idx');
        });

        // --- 3. The self-checkout lane -----------------------------------------
        Schema::create('self_checkout_carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // The lane. Required — a self-checkout basket with no lane behind it
            // is a basket nobody can be sent to.
            $table->foreignId('counter_id')->constrained('pos_counters')->cascadeOnDelete();
            $table->string('cart_number', 30);
            /**
             * scanning          — the customer is still filling it
             * awaiting_approval — something on it needs a human
             * ready             — cleared, waiting to be paid for
             * completed         — rung up; invoice_id is set
             * abandoned         — walked away from, or cleared down by staff
             */
            $table->enum('status', ['scanning', 'awaiting_approval', 'ready', 'completed', 'abandoned'])
                ->default('scanning');
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();

            /**
             * The sale, once there is one. Nullable throughout the cart's life
             * and set exactly once, by `complete` — the cart never creates the
             * sale itself. See SelfCheckoutService.
             */
            $table->foreignId('sales_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'cart_number'], 'self_checkout_carts_number_unique');
            $table->index(['tenant_id', 'status'], 'self_checkout_carts_status_idx');
            $table->index(['tenant_id', 'counter_id', 'status'], 'self_checkout_carts_lane_idx');
        });

        Schema::create('self_checkout_cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cart_id')->constrained('self_checkout_carts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->decimal('quantity', 12, 3)->default(1);
            // Snapshotted off the product at scan time. The customer is looking
            // at a running total on a screen, and a price that moves while they
            // are still shopping is a dispute at the lane.
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('tax_percent', 5, 2)->default(0);
            /**
             * Why this line stopped the basket, if it did. Two independent
             * things, because they clear differently: `needs_approval` is
             * satisfied by an attendant, and a `voided` line is one the customer
             * took back out — which in a real store is itself a supervisor act,
             * since anybody who can silently unscan can walk out with the goods.
             */
            $table->boolean('needs_approval')->default(false);
            $table->string('approval_reason', 120)->nullable();
            $table->boolean('is_voided')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'cart_id'], 'self_checkout_items_cart_idx');
        });

        // --- 4. The one product attribute a lane needs --------------------------
        Schema::table('products', function (Blueprint $table) {
            // Alcohol, tobacco, blades. The line an unattended lane must not sell
            // without somebody having looked at the customer.
            $table->boolean('is_age_restricted')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_age_restricted');
        });

        Schema::dropIfExists('self_checkout_cart_items');
        Schema::dropIfExists('self_checkout_carts');
        Schema::dropIfExists('shelf_placements');

        Schema::table('warehouse_locations', function (Blueprint $table) {
            $table->dropIndex('warehouse_locations_shelf_idx');
            $table->dropColumn(['is_shelf', 'aisle', 'bay', 'shelf_level']);
        });
    }
};
