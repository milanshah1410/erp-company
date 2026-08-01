<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Food & Beverage plugin schema: recipes, menu attributes, parcel/delivery and
 * the per-table QR token.
 *
 * The organising idea is that a restaurant does not sell stock, it sells *cooked
 * stock*. Nobody keeps four Paneer Tikkas on a shelf; they keep paneer, cream and
 * a gas cylinder. So the existing restaurant tables — which model the floor and
 * the kitchen perfectly well — needed one thing added underneath them: a
 * statement of what each dish is made of, so that billing a dish can move the
 * ingredients it consumed.
 *
 * Four groups of change:
 *
 *  1. restaurant_recipes / _items — the dish → ingredient statement. A separate
 *     table rather than reusing `boms`: a BOM belongs to Manufacturing and drags
 *     that module's gating and lifecycle with it, and a restaurant that has
 *     switched Manufacturing off must still be able to cost a dish.
 *
 *  2. Menu attributes on products — veg/non-veg marking and a sold-out switch.
 *     Both live on the product because the menu *is* the product master here;
 *     inventing a parallel menu_items table would leave two names for one dish.
 *
 *  3. Parcel and delivery on restaurant_orders — packaging and delivery are
 *     charged, and a delivery goes out with an address and a rider. These are
 *     header fields, not lines, because the customer sees them as charges on the
 *     bill rather than as things ordered.
 *
 *  4. A QR token per table, so a printed sticker resolves to a public menu.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- 1. Recipes -----------------------------------------------------
        Schema::create('restaurant_recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            /**
             * How many servings the ingredient quantities below produce. A recipe
             * written per-kilo of gravy is far easier for a chef to key than one
             * scaled to a single plate, so consumption divides by this.
             */
            $table->decimal('yield_quantity', 15, 3)->default(1);
            // Snapshot roll-up of ingredient cost, for the food-cost column.
            $table->decimal('ingredient_cost', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            /**
             * One recipe per dish, enforced rather than merely intended: two
             * active recipes for one dish would make "what did this plate
             * consume?" ambiguous at exactly the moment stock is being moved.
             * Revisions belong in a future versioned table, not in duplicates.
             */
            $table->unique(['tenant_id', 'product_id'], 'restaurant_recipes_product_unique');
        });

        Schema::create('restaurant_recipe_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_recipe_id')->constrained('restaurant_recipes')->cascadeOnDelete();
            // The ingredient. A stocked product — paneer, oil, a paper box.
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->decimal('quantity', 15, 4);
            /**
             * Trim, spillage and peel. Kept apart from `quantity` so the chef's
             * recipe stays the chef's recipe and the kitchen's real draw-down is
             * still honest about waste.
             */
            $table->decimal('wastage_percent', 8, 3)->default(0);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('total_cost', 15, 2)->default(0);
            $table->string('remarks')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'restaurant_recipe_id'], 'restaurant_recipe_items_recipe_idx');
        });

        // --- 2. Menu attributes on the product master -----------------------
        Schema::table('products', function (Blueprint $table) {
            /**
             * The green/red dot Indian menus are legally expected to carry.
             * Nullable because most of the catalogue is not food at all.
             */
            $table->enum('food_type', ['veg', 'non_veg', 'egg', 'vegan'])
                ->nullable()->after('prep_minutes');
            /**
             * Sold out for now. Distinct from `is_active`: a dish off today
             * because the fish did not arrive is not a dish withdrawn from the
             * menu, and conflating them loses the price, recipe and history when
             * it comes back tomorrow.
             */
            $table->boolean('is_available')->default(true)->after('food_type');
            // Menu ordering within a category, so the card reads the way the
            // shop wants it rather than alphabetically.
            $table->unsignedInteger('menu_sort_order')->default(0)->after('is_available');
        });

        // --- 3. Parcel + delivery on the ticket -----------------------------
        Schema::table('restaurant_orders', function (Blueprint $table) {
            // Charged on takeaway/parcel: boxes, bags, foil.
            $table->decimal('packaging_charge', 15, 2)->default(0)->after('service_charge');
            $table->decimal('delivery_charge', 15, 2)->default(0)->after('packaging_charge');
            $table->text('delivery_address')->nullable()->after('delivery_charge');
            $table->string('delivery_phone', 20)->nullable()->after('delivery_address');
            /**
             * Who is carrying it. An employee rather than a free-text name so the
             * rider's runs are countable, and nullable because an order can be
             * keyed before anyone has been assigned to take it.
             */
            $table->foreignId('rider_id')->nullable()->after('delivery_phone')
                ->constrained('employees')->nullOnDelete();
            $table->timestamp('dispatched_at')->nullable()->after('rider_id');
            $table->timestamp('delivered_at')->nullable()->after('dispatched_at');
        });

        // --- 4. Per-table QR token ------------------------------------------
        Schema::table('restaurant_tables', function (Blueprint $table) {
            /**
             * Resolves a scanned sticker to this tenant and table without a
             * login. Unique across tenants because the token *is* the whole
             * lookup — see PublicMenuController.
             */
            $table->string('qr_token', 64)->nullable()->unique()->after('sort_order');
        });

        if ($this->isMysql()) {
            // Ingredients leaving the store into a cooked dish. Read the live
            // column rather than restating the list: several migrations have
            // already widened this enum and hard-coding would drop the ones this
            // file does not know about.
            $types = $this->ledgerTypes();
            $types[] = 'recipe_consume';
            $this->setLedgerTypes(array_values(array_unique($types)));
        }
    }

    public function down(): void
    {
        if ($this->isMysql()) {
            DB::table('stock_ledgers')->where('type', 'recipe_consume')->delete();
            $this->setLedgerTypes(array_values(array_diff($this->ledgerTypes(), ['recipe_consume'])));
        }

        Schema::table('restaurant_tables', function (Blueprint $table) {
            $table->dropUnique(['qr_token']);
            $table->dropColumn('qr_token');
        });

        Schema::table('restaurant_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rider_id');
            $table->dropColumn([
                'packaging_charge', 'delivery_charge', 'delivery_address',
                'delivery_phone', 'dispatched_at', 'delivered_at',
            ]);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['food_type', 'is_available', 'menu_sort_order']);
        });

        Schema::dropIfExists('restaurant_recipe_items');
        Schema::dropIfExists('restaurant_recipes');
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
