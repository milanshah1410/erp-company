<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retail plugin schema — the garment trade's registers, plus the one extension
 * it makes to core.
 *
 * Five groups of change:
 *
 *  1. garment_fabrics — what a style is cut from. A retail fabric record is not
 *     a `fabric_rolls` row: the mill's table tracks a physical roll through
 *     inspection, shrinkage and cutting, whereas a showroom only ever needs to
 *     answer "what is this made of, and how do I wash it?". Forcing the counter
 *     to create roll records to describe a shirt would be absurd, so these are
 *     separate tables serving separate trades.
 *
 *  2. garment_size_sets / garment_colours — the two axes of the matrix. Size is
 *     a *set* rather than a list of individual rows because sizes are only ever
 *     used as an ordered run ("XS–XXXL", "28–42 even"), and the order is
 *     load-bearing: it is the column order of the grid and the sort of every
 *     size-wise stock report. Colour is one row per colour because colours are
 *     picked individually and carry a swatch.
 *
 *  3. garment_styles — the design number. The unit the trade actually speaks in:
 *     a buyer orders a style, a supplier quotes a style, a shop marks down a
 *     style. Products hang off it (see 6) rather than the reverse, because one
 *     style is thirty products once it is cut.
 *
 *  4. garment_trials / garment_trial_items — the trial room. See TrialService
 *     for why an issue reserves stock instead of moving it.
 *
 *  5. `products.style_id` — nullable and nullOnDelete, so unregistering the
 *     plugin leaves nothing behind but an unused column.
 *
 * Two things a reader might expect here and will not find, both for the same
 * reason — a plugin migration must not own a column that *core* code reads, or
 * dropping the vertical breaks the core module:
 *
 *  - The retail alteration columns on `tailor_alterations` are a core migration
 *    (2026_08_03_110000), alongside the rest of the tailoring schema.
 *  - Gift vouchers are core too (2026_08_03_110100). A voucher is a Loyalty &
 *    Offers feature that a gift shop or a supermarket wants exactly as much as a
 *    garment showroom, and it is spent through PosController — which cannot be
 *    made to depend on a vertical that may not be installed.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- 1. The fabric register -----------------------------------------
        Schema::create('garment_fabrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 120);
            // "60% cotton 40% polyester" — kept as free text rather than a
            // percentage table because it is printed on a label and read by a
            // human, never computed with.
            $table->string('composition', 160)->nullable();
            $table->string('weave', 60)->nullable();
            // Grams per square metre, and the width off the roll in inches —
            // the two numbers a buyer uses to work out consumption per piece.
            $table->unsignedSmallInteger('gsm')->nullable();
            $table->decimal('width_inch', 6, 2)->nullable();
            $table->decimal('shrinkage_percent', 5, 2)->nullable();
            $table->string('care', 255)->nullable();
            $table->decimal('rate', 12, 2)->default(0);
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'garment_fabrics_code_unique');
            $table->index(['tenant_id', 'is_active'], 'garment_fabrics_active_idx');
        });

        // --- 2a. Size sets ---------------------------------------------------
        Schema::create('garment_size_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            /**
             * The ordered run of sizes, e.g. ["XS","S","M","L","XL","XXL"].
             * A JSON list rather than child rows: the set is always read whole,
             * never queried into, and its *order* is the grid's column order —
             * which a child table would make an extra column to maintain and an
             * extra thing to get wrong.
             */
            $table->json('sizes');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name'], 'garment_size_sets_name_unique');
        });

        // --- 2b. Colours -----------------------------------------------------
        Schema::create('garment_colours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            // The shop's own short code — what goes into the SKU suffix, so it
            // is kept short and upper-cased on write.
            $table->string('code', 12);
            // Swatch for the grid. Cosmetic, and nullable for exactly that
            // reason: nobody should be blocked from adding "Mehendi" because
            // they do not know its hex.
            $table->string('hex', 7)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'garment_colours_code_unique');
            $table->index(['tenant_id', 'is_active'], 'garment_colours_active_idx');
        });

        // --- 3. The style master ---------------------------------------------
        Schema::create('garment_styles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // The design number. This is what everyone in the trade calls it by,
            // so it is unique per tenant and required.
            $table->string('code', 40);
            $table->string('name', 160);

            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('fabric_id')->nullable()->constrained('garment_fabrics')->nullOnDelete();
            // The default grid this style is cut in. Only a default — the matrix
            // screen can override it per style without editing the set.
            $table->foreignId('size_set_id')->nullable()->constrained('garment_size_sets')->nullOnDelete();

            $table->enum('gender', ['mens', 'womens', 'boys', 'girls', 'unisex', 'infant'])->nullable();
            $table->string('season', 40)->nullable();
            $table->string('fit', 40)->nullable();
            $table->string('sleeve', 40)->nullable();
            $table->string('neck', 40)->nullable();
            $table->string('pattern', 40)->nullable();

            /**
             * Price at the style, not the SKU. Every variant a style produces is
             * sold at the same price in this trade — a large costs what a medium
             * costs — so the matrix stamps these onto the variants it creates and
             * a per-SKU override remains possible afterwards for the odd case.
             */
            $table->decimal('mrp', 12, 2)->default(0);
            $table->decimal('cost_price', 12, 2)->default(0);

            $table->string('image', 255)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'garment_styles_code_unique');
            $table->index(['tenant_id', 'is_active'], 'garment_styles_active_idx');
            $table->index(['tenant_id', 'season'], 'garment_styles_season_idx');
        });

        // --- 4. The trial room ------------------------------------------------
        Schema::create('garment_trials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('trial_number', 40);
            /**
             * Required, unlike almost every other customer_id in the schema. A
             * trial is goods leaving the shop on somebody's word; "walk-in" is
             * not a person you can telephone on Thursday when they have not come
             * back, so the counter has to name one.
             */
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();

            $table->timestamp('issued_at');
            $table->date('due_at');
            /**
             * open → closed once every piece is accounted for. `converted` is a
             * closed trial where at least one piece was kept and billed;
             * `cancelled` is one that never actually left. Kept apart from a bare
             * closed/open flag so the trial book can report the number that
             * matters — how many trials turn into sales.
             */
            $table->enum('status', ['open', 'closed', 'converted', 'cancelled'])->default('open');

            // Security taken against the goods, if any, and how it was taken.
            // Bookkeeping only: a deposit is not a payment against a bill that
            // does not exist yet, so nothing is posted to accounts here.
            $table->decimal('deposit_amount', 12, 2)->default(0);
            $table->string('deposit_mode', 20)->nullable();
            $table->boolean('deposit_refunded')->default(false);

            $table->timestamp('closed_at')->nullable();
            // The bill the kept pieces were rung up on. Set when the counter
            // links the sale; the sale itself goes through POS like any other.
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'trial_number'], 'garment_trials_number_unique');
            $table->index(['tenant_id', 'status', 'due_at'], 'garment_trials_overdue_idx');
            $table->index(['tenant_id', 'customer_id'], 'garment_trials_customer_idx');
        });

        Schema::create('garment_trial_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trial_id')->constrained('garment_trials')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();

            $table->decimal('quantity', 12, 3);
            // What it would sell for, snapshotted at issue. The customer was
            // quoted a price when the garment left the shop and a later markdown
            // must not silently rewrite what the trial slip said.
            $table->decimal('unit_price', 12, 2)->default(0);

            // How the piece came back. These two always sum to at most quantity;
            // anything left over is still out with the customer.
            $table->decimal('returned_quantity', 12, 3)->default(0);
            $table->decimal('sold_quantity', 12, 3)->default(0);

            $table->string('remarks', 255)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'trial_id'], 'garment_trial_items_trial_idx');
            $table->index(['tenant_id', 'product_id'], 'garment_trial_items_product_idx');
        });

        // --- 5. Extension to core ---------------------------------------------
        Schema::table('products', function (Blueprint $table) {
            /**
             * The style this SKU was cut from. Nullable and nullOnDelete: the
             * same products table serves every other trade, and removing a style
             * from the master must not take the stock and sales history of forty
             * SKUs with it.
             */
            $table->foreignId('style_id')->nullable()->after('brand_id')
                ->constrained('garment_styles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('style_id');
        });

        Schema::dropIfExists('garment_trial_items');
        Schema::dropIfExists('garment_trials');
        Schema::dropIfExists('garment_styles');
        Schema::dropIfExists('garment_colours');
        Schema::dropIfExists('garment_size_sets');
        Schema::dropIfExists('garment_fabrics');
    }
};
