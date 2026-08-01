<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Food & Beverage — the seven trades beyond Restaurant.
 *
 * The first plugin migration built the restaurant: recipes, menu attributes,
 * parcel/delivery and the table QR. Everything a fine-dine kitchen needs was
 * there, and everything the *other* seven trades need was declared 'planned' in
 * FoodBeveragePlugin so the trade picker would not lie about it. This migration
 * is that debt paid: each 'planned' feature now has a table behind it.
 *
 * The trades are genuinely different shops, so the temptation was to give each
 * one its own everything. Resisted. Three observations collapse most of it:
 *
 *  1. A combo and a happy hour are both "the menu price is not the product
 *     price right now". One is a bundle, one is a clock — but both end up as an
 *     adjustment applied while pricing a ticket, so they live together as menu
 *     offers and are read by one service.
 *
 *  2. A cake order, a milk round and a membership are all things sold. None of
 *     them gets its own money columns: each raises a SalesOrder + Invoice on its
 *     own `channel` when it is time to bill, which is the same spine POS, B2B,
 *     e-commerce, service and the restaurant already bill through. What lives
 *     here is only what the sales spine cannot say — the cake's inscription, the
 *     fat percentage of the milk, the membership's expiry.
 *
 *  3. A flavour is a product variant and a freezer is a warehouse location.
 *     Neither needs a table; the parlour needs one flag (`is_freezer`) so the
 *     screen knows which locations to read, and nothing else.
 *
 * Nine groups of change, in the order the plugin declares the trades.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createCombos();
        $this->createHappyHours();
        $this->createMemberships();
        $this->addTokenQueue();
        $this->createCakeOrders();
        $this->createMilkRateCharts();
        $this->createMilkCollections();
        $this->createMilkSubscriptions();
        $this->markFreezers();
        $this->widenSalesChannels();
        $this->widenLedgerTypes();
    }

    /*
    |--------------------------------------------------------------------------
    | 1. Combos — cafe, fast food
    |--------------------------------------------------------------------------
    |
    | "Burger + fries + cola for 199" is sold as one thing at one price, and the
    | kitchen still has to cook three. So a combo is a *product* — it has a price,
    | a tax rate, an HSN code and a place on the menu card like anything else —
    | plus a statement of what it breaks into.
    |
    | Modelling it as a product rather than a fourth kind of order line is what
    | keeps it out of everyone else's way: POS bills it, the menu screen lists it,
    | GST reports it, and a shop that never sells combos never notices. The only
    | code that has to know is the consumption pass at settle time, which expands
    | the combo into its components and then costs each of those from its own
    | recipe (RecipeConsumptionService).
    */
    private function createCombos(): void
    {
        Schema::table('products', function (Blueprint $table) {
            /**
             * Marks the product as a bundle. Denormalised onto the product on
             * purpose: the menu card and the POS grid both need to know at a
             * glance, and joining menu_combos on every product read to answer
             * "is this a combo?" would be a join for a boolean.
             */
            $table->boolean('is_combo')->default(false)->after('menu_sort_order');
        });

        Schema::create('menu_combos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            /**
             * The sellable combo. One combo definition per product, enforced:
             * two definitions for one product would make "what does this break
             * into?" ambiguous at exactly the moment stock is being moved.
             */
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('name');
            /**
             * Sum of the components' own menu prices, snapshotted when the combo
             * is saved. Kept so the menu can show "₹240 worth for ₹199" without
             * re-pricing the components on every read — and so the saving is the
             * saving that was advertised, not one that drifts when a component's
             * price changes.
             */
            $table->decimal('component_value', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'product_id'], 'menu_combos_product_unique');
        });

        Schema::create('menu_combo_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_combo_id')->constrained('menu_combos')->cascadeOnDelete();
            /**
             * A component is a menu dish, not an ingredient: the combo says
             * "one burger", and what a burger is made of stays the burger's
             * recipe. That indirection is the whole point — change the bun
             * supplier once and every combo containing a burger follows.
             */
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->decimal('quantity', 15, 3)->default(1);
            /**
             * Whether the guest may swap this line (cola → lemonade). Stored but
             * not yet acted on at the till; declared here because a combo whose
             * choices are unrecorded cannot later be given a choice UI without a
             * migration on live data.
             */
            $table->boolean('is_swappable')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'menu_combo_id'], 'menu_combo_items_combo_idx');
        });
    }

    /*
    |--------------------------------------------------------------------------
    | 2. Happy hours — cafe
    |--------------------------------------------------------------------------
    |
    | A time-window price rule: 30% off beverages, weekdays 16:00–19:00. Three
    | things make it more than a discount field.
    |
    |  - It is a *clock*, so it has to be evaluated when the ticket is priced,
    |    not when the shop remembers to switch something on.
    |  - It is scoped: all items, one category, or a hand-picked list.
    |  - It has to be auditable after the fact. A guest who was charged full
    |    price at 18:55 will ask, and "the manager had turned it off" is only an
    |    answer if the window and its dates are recorded.
    */
    private function createHappyHours(): void
    {
        Schema::create('menu_happy_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            /**
             * Which days it runs. ISO-8601 day numbers (1 = Monday … 7 = Sunday)
             * as a JSON list, because the rule is "weekdays" or "weekends" far
             * more often than it is a date range, and seven boolean columns would
             * make that query unreadable.
             */
            $table->json('days');
            $table->time('start_time');
            /**
             * A window may cross midnight — a late bar runs 22:00 to 01:00. Stored
             * as given and interpreted as a wrap by the evaluator rather than
             * split into two rows, so the shop sees back the hours it typed.
             */
            $table->time('end_time');
            // Seasonal offers end. Null = runs until switched off.
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            /**
             * What it applies to. 'category' reads category_id; 'products' reads
             * the pivot; 'all' is the whole card.
             */
            $table->enum('scope', ['all', 'category', 'products'])->default('all');
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            /**
             * 'percent' | 'flat' — the same vocabulary DocumentPricingService and
             * every other discount in the system speaks, so a winning offer's
             * type can be copied straight onto the order line rather than
             * translated at the one place a mistranslation would be silent.
             */
            $table->enum('discount_type', ['percent', 'flat'])->default('percent');
            $table->decimal('discount_value', 15, 2)->default(0);
            /**
             * Order types the offer is honoured on. A café discounting its own
             * counter does not want the same price going out on an aggregator
             * delivery, and that distinction is the difference between an offer
             * and a loss.
             */
            $table->enum('applies_to', ['all', 'dine_in', 'takeaway', 'delivery'])->default('all');
            /**
             * Priority when two windows overlap. Highest wins, and exactly one
             * offer applies to a line — stacking discounts is how a shop
             * accidentally sells at cost.
             */
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active'], 'menu_happy_hours_active_idx');
        });

        /**
         * Which offer priced a line, recorded on the line itself.
         *
         * Two jobs. It is the audit trail — a guest charged full price at 18:55
         * will ask, and "the window had closed" is only an answer if the offer
         * that did or did not apply is on the bill. And it is how re-pricing
         * tells an offer discount from a manager's manual one: an offer line is
         * re-evaluated on every reprice, a manual discount is left alone.
         */
        Schema::table('restaurant_order_items', function (Blueprint $table) {
            $table->foreignId('menu_happy_hour_id')->nullable()->after('discount_amount')
                ->constrained('menu_happy_hours')->nullOnDelete();
        });

        Schema::create('menu_happy_hour_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_happy_hour_id')->constrained('menu_happy_hours')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['menu_happy_hour_id', 'product_id'], 'menu_happy_hour_items_unique');
        });
    }

    /*
    |--------------------------------------------------------------------------
    | 3. Memberships — cafe
    |--------------------------------------------------------------------------
    |
    | Distinct from Loyalty, which already ships. Loyalty is earned: spend, accrue
    | points, redeem. A membership is *bought* — ₹1,000 for a year of 10% off and
    | free refills — so it is a sale with an expiry date, and it entitles the
    | holder to a price the moment it is sold rather than after they have spent
    | enough to deserve one.
    |
    | Two tables: the tiers a shop sells, and who currently holds one.
    */
    private function createMemberships(): void
    {
        Schema::create('membership_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 15, 2)->default(0);
            /**
             * Validity in days rather than months: "90 days" is what a café means
             * by a quarterly card, and calendar-month arithmetic on a card sold
             * on the 31st is a bug waiting to be filed.
             */
            $table->unsignedInteger('duration_days')->default(365);
            // The entitlement. A flat percentage is what all but the most
            // elaborate schemes actually are.
            $table->decimal('discount_percent', 5, 2)->default(0);
            /**
             * Points multiplier for members, applied by the Loyalty module when
             * it accrues. 1.00 = members earn like everyone else, so the two
             * schemes compose instead of competing.
             */
            $table->decimal('loyalty_multiplier', 5, 2)->default(1);
            // Free-text perks that are honoured by staff, not by the system —
            // "free birthday cake". Recorded so the card can print them.
            $table->text('benefits')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'membership_plans_code_unique');
        });

        Schema::create('customer_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('membership_plan_id')->constrained('membership_plans')->cascadeOnDelete();
            // Printed on the card. Unique per tenant so it can be typed at the
            // till by someone holding the card and nothing else.
            $table->string('member_number', 50);
            $table->date('starts_on');
            $table->date('ends_on');
            /**
             * 'active' is not derived from the dates alone: a card can be
             * cancelled mid-term (a refund, a barred customer) and must stop
             * entitling immediately while its history stays readable.
             */
            $table->enum('status', ['active', 'expired', 'cancelled'])->default('active');
            // What was actually charged — the plan's price today is not what this
            // member paid last year.
            $table->decimal('price_paid', 15, 2)->default(0);
            /**
             * The sale. Nullable because a shop will comp a card for a regular,
             * and a membership that cannot be given away is a membership staff
             * will record as a discount instead — losing the expiry date.
             */
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'member_number'], 'customer_memberships_number_unique');

            /**
             * Not unique on (tenant, customer): a customer renews, and last
             * year's card is history worth keeping. "One *active* card" is a rule
             * the service enforces, because SQL cannot express it without a
             * partial index MySQL does not have.
             */
            $table->index(['tenant_id', 'customer_id', 'status'], 'customer_memberships_holder_idx');
            $table->index(['tenant_id', 'ends_on'], 'customer_memberships_expiry_idx');
        });

        /**
         * Which card got the discount on a ticket.
         *
         * The same reasoning as the happy hour on a line: the entitlement is
         * applied automatically, so the bill has to say which card applied it.
         * It is also how repricing tells a member discount from a manager's own —
         * a member ticket is re-evaluated, a manually discounted one is not.
         */
        Schema::table('restaurant_orders', function (Blueprint $table) {
            $table->foreignId('customer_membership_id')->nullable()->after('customer_id')
                ->constrained('customer_memberships')->nullOnDelete();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | 4. Token queue — fast food
    |--------------------------------------------------------------------------
    |
    | A QSR counter has no tables. It takes money, hands over a number, and calls
    | that number when the tray is ready. The restaurant tables already model the
    | ticket and the kitchen perfectly; what a counter needs is the number and its
    | state, so these are columns on the order rather than a parallel document.
    |
    | Deliberately *not* reusing `order_number`: that is a permanent accounting
    | identity, and a token is a small number that resets every day so it can be
    | shouted across a room.
    */
    private function addTokenQueue(): void
    {
        Schema::table('restaurant_orders', function (Blueprint $table) {
            // Null for a shop that does not run a counter queue.
            $table->unsignedInteger('token_number')->nullable()->after('order_type');
            $table->enum('token_status', ['waiting', 'preparing', 'ready', 'called', 'collected'])
                ->nullable()->after('token_number');
            // When the number went up on the board. Kept for the counter's own
            // service-time reporting: issued → ready is the number a QSR manages.
            $table->timestamp('token_called_at')->nullable()->after('token_status');
            $table->timestamp('token_collected_at')->nullable()->after('token_called_at');

            /**
             * Tokens restart daily, so the number alone is not unique. Scoping the
             * uniqueness to the business date is what makes "token 14" mean one
             * order — without it, calling 14 on a busy evening could match
             * yesterday's 14 as well.
             */
            $table->date('token_date')->nullable()->after('token_collected_at');
            $table->unique(['tenant_id', 'token_date', 'token_number'], 'restaurant_orders_token_unique');
        });
    }

    /*
    |--------------------------------------------------------------------------
    | 5. Cake orders — bakery
    |--------------------------------------------------------------------------
    |
    | A dated, part-paid, decorated-to-order cake. The plugin called this "closest
    | existing shape is a sales order" and that is still true of the money — so
    | the money stays on a sales order and this table holds only what a sales
    | order cannot say: the inscription, the weight, the shape, the reference
    | photo the customer sent on WhatsApp, and the hour it must be collected.
    |
    | The advance is the reason this is a document and not just a future-dated
    | invoice: the shop takes ₹500 today against a ₹2,000 cake for Saturday, and
    | needs to know all week what it owes the oven and what the customer owes it.
    */
    private function createCakeOrders(): void
    {
        Schema::create('cake_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('order_number', 50);
            /**
             * Nullable: most cake orders are taken over a counter from someone
             * who will never be a ledger customer. The name and phone below are
             * then the whole record — and requiring a customer row first is how a
             * shop ends up with two hundred "Walk-in" duplicates.
             */
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('customer_name');
            $table->string('customer_phone', 20)->nullable();
            /**
             * The catalogue cake this is based on, if any. Nullable because a
             * fully bespoke cake is priced by the baker, not from the shelf.
             */
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('flavour')->nullable();
            $table->decimal('weight_kg', 8, 3)->default(0);
            $table->enum('shape', ['round', 'square', 'heart', 'rectangle', 'tiered', 'custom'])->default('round');
            $table->unsignedTinyInteger('tiers')->default(1);
            // Eggless is a religious requirement for a large share of Indian
            // cake buyers, not a preference — it is asked on every order.
            $table->boolean('is_eggless')->default(false);
            // The inscription. Its own column because it is the single most
            // misprinted field in a bakery and it belongs on the docket.
            $table->string('message_on_cake')->nullable();
            $table->text('design_notes')->nullable();
            /**
             * Reference photo path on the tenant's private disk (App\Support\
             * TenantStorage), not a URL: the picture is customer material and is
             * served through a signed route like every other tenant upload.
             */
            $table->string('reference_image')->nullable();
            // The whole point of the document. Datetime, not date: "Saturday" is
            // not an instruction a bakery can work to.
            $table->timestamp('required_at');
            $table->enum('fulfilment', ['pickup', 'delivery'])->default('pickup');
            $table->text('delivery_address')->nullable();
            $table->decimal('delivery_charge', 15, 2)->default(0);
            $table->decimal('price', 15, 2)->default(0);
            $table->decimal('advance_amount', 15, 2)->default(0);
            /**
             * Lifecycle. 'ready' is distinct from 'delivered' because a finished
             * cake sitting in the display fridge waiting for a customer who is
             * late is the normal Saturday state of a bakery.
             */
            $table->enum('status', ['booked', 'in_production', 'ready', 'delivered', 'cancelled'])
                ->default('booked');
            // Filled on delivery, when the sale is finally raised.
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'order_number'], 'cake_orders_number_unique');
            // The board is "what is due next", so the date leads the index.
            $table->index(['tenant_id', 'required_at'], 'cake_orders_due_idx');
            $table->index(['tenant_id', 'status'], 'cake_orders_status_idx');
        });
    }

    /*
    |--------------------------------------------------------------------------
    | 6. Milk rate charts — dairy
    |--------------------------------------------------------------------------
    |
    | Milk is not bought by the litre. It is bought by the *fat* in the litre, and
    | in an organised dairy by fat and SNF together — which is why a collection
    | centre has a chart on the wall and a Lactoscan on the counter.
    |
    | Three bases are supported because all three are in daily use: a flat rate at
    | a village collection point that has no tester, fat-only at most private
    | dairies, and fat + SNF where the plant pays on both.
    */
    private function createMilkRateCharts(): void
    {
        Schema::create('milk_rate_charts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('milk_type', ['cow', 'buffalo', 'mixed'])->default('cow');
            $table->enum('basis', ['flat', 'fat', 'fat_snf'])->default('fat');
            // 'flat' basis: the whole rate.
            $table->decimal('flat_rate', 15, 4)->default(0);
            /**
             * 'fat' basis: rate = fat_percent × rate_per_fat_point. The Indian
             * trade calls this the "fat rate" and quotes it as ₹ per kg-fat per
             * litre, so four decimals — a paisa of error over a 5,000-litre
             * morning is real money.
             */
            $table->decimal('rate_per_fat_point', 15, 4)->default(0);
            // 'fat_snf' basis adds the SNF leg on top.
            $table->decimal('rate_per_snf_point', 15, 4)->default(0);
            /**
             * Acceptance band. Milk outside it is watered or soured; the entry
             * screen warns rather than refuses, because the decision to reject a
             * farmer's can is a person's, taken at the counter.
             */
            $table->decimal('min_fat', 5, 2)->default(0);
            $table->decimal('max_fat', 5, 2)->default(15);
            // Incentive/deduction applied to the computed rate, in ₹ per litre.
            $table->decimal('adjustment_per_litre', 15, 4)->default(0);
            $table->date('effective_from');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active', 'effective_from'], 'milk_rate_charts_active_idx');
        });
    }

    /*
    |--------------------------------------------------------------------------
    | 7. Milk collections — dairy
    |--------------------------------------------------------------------------
    |
    | The morning and evening round: what came in from each farmer, how good it
    | was, and therefore what the dairy owes for it.
    |
    | The farmer is a *supplier*. That is the load-bearing decision here: it means
    | a collection settles into the same payables ledger as a feed bill, the
    | dairy's ageing report includes its farmers, and none of the accounting is
    | re-implemented. What this table adds is the quality reading the supplier
    | ledger has no vocabulary for.
    */
    private function createMilkCollections(): void
    {
        Schema::create('milk_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('collection_number', 50);
            // The farmer.
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->date('collection_date');
            // Milk is collected twice a day, every day, and the two are priced
            // and reported separately.
            $table->enum('shift', ['morning', 'evening'])->default('morning');
            $table->enum('milk_type', ['cow', 'buffalo', 'mixed'])->default('cow');
            /**
             * Litres. Weight in kg is what the platform scale actually reads, but
             * the trade contracts, pays and reports in litres, so litres is what
             * is stored and the conversion stays at the counter.
             */
            $table->decimal('quantity_litres', 15, 3);
            $table->decimal('fat_percent', 6, 2)->default(0);
            $table->decimal('snf_percent', 6, 2)->default(0);
            // Corrected lactometer reading, kept because it is what an older
            // tester gives you and what SNF is derived from.
            $table->decimal('clr', 6, 2)->default(0);
            $table->foreignId('milk_rate_chart_id')->nullable()->constrained('milk_rate_charts')->nullOnDelete();
            /**
             * Rate and amount are *stored*, not derived on read. The chart will be
             * edited — a dairy revises its fat rate with the season — and what the
             * farmer was paid for Tuesday's can must not change when it does.
             */
            $table->decimal('rate_per_litre', 15, 4)->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            // Which round it came in on, for route-wise volumes.
            $table->foreignId('delivery_route_id')->nullable()->constrained('delivery_routes')->nullOnDelete();
            /**
             * Settlement. A dairy pays its farmers for a period — a fortnight,
             * usually — as one bill, so the link is to a purchase invoice raised
             * over many collections rather than a payment per can.
             */
            $table->foreignId('purchase_invoice_id')->nullable()->constrained('purchase_invoices')->nullOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->string('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'collection_number'], 'milk_collections_number_unique');
            /**
             * One entry per farmer per shift per day. A second can from the same
             * farmer in the same shift is an amendment to the first, not a new
             * row — and without this constraint the fortnight's payable silently
             * doubles when someone keys it twice.
             */
            $table->unique(
                ['tenant_id', 'supplier_id', 'collection_date', 'shift'],
                'milk_collections_farmer_shift_unique',
            );
            $table->index(['tenant_id', 'collection_date', 'shift'], 'milk_collections_round_idx');
        });
    }

    /*
    |--------------------------------------------------------------------------
    | 8. Milk subscriptions — dairy
    |--------------------------------------------------------------------------
    |
    | The other half of a dairy: a household takes half a litre every morning and
    | pays at the end of the month. It is a standing order, so almost none of it
    | is a sale until the month ends.
    |
    | Two tables, and the split matters. The subscription is the *standing
    | instruction* — what, how much, which days, on whose round. The deliveries
    | are what actually happened, one row per day, including the days it did not:
    | the family travelled, or the boy did not come. A month billed from the
    | instruction rather than from the deliveries is a month of arguments, so the
    | invoice is built from the delivery rows and nothing else.
    */
    private function createMilkSubscriptions(): void
    {
        Schema::create('milk_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('subscription_number', 50);
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->decimal('quantity_per_day', 15, 3)->default(1);
            /**
             * The agreed rate. Held on the subscription, not read from the product
             * each morning: a household on an old rate stays on it until the dairy
             * tells them otherwise, which is exactly what a subscription is.
             */
            $table->decimal('rate', 15, 2)->default(0);
            $table->enum('shift', ['morning', 'evening', 'both'])->default('morning');
            // ISO day numbers, as for happy hours. Alternate-day customers are
            // common enough that a boolean "daily" would not survive contact.
            $table->json('days');
            $table->foreignId('delivery_route_id')->nullable()->constrained('delivery_routes')->nullOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            /**
             * 'paused' is its own state rather than an end date and a new row: a
             * family away for a fortnight comes back to the same standing order,
             * the same rate and the same running balance.
             */
            $table->enum('status', ['active', 'paused', 'cancelled'])->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'subscription_number'], 'milk_subscriptions_number_unique');
            // The round sheet: "who is on route 3 this morning".
            $table->index(['tenant_id', 'status', 'delivery_route_id'], 'milk_subscriptions_round_idx');
        });

        Schema::create('milk_subscription_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('milk_subscription_id')->constrained('milk_subscriptions')->cascadeOnDelete();
            $table->date('delivery_date');
            $table->enum('shift', ['morning', 'evening'])->default('morning');
            /**
             * What was left at the door. Zero is meaningful and is not the same as
             * no row: zero says the boy went and the family declined, no row says
             * nobody has told us yet.
             */
            $table->decimal('quantity', 15, 3)->default(0);
            // Rate snapshotted per delivery, so a mid-month revision bills the
            // days before it at the old rate and the days after at the new one.
            $table->decimal('rate', 15, 2)->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            /**
             * 'extra' is a real daily event — a guest arrives and the household
             * asks for an extra litre — and it must be billable without editing
             * the standing order.
             */
            $table->enum('status', ['delivered', 'skipped', 'extra'])->default('delivered');
            /**
             * Set when the month is billed. Its presence is what makes the
             * billing run idempotent: a second run over the same period picks up
             * only the rows that are still unbilled, so a re-run after a crash
             * cannot invoice a household twice.
             */
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('remarks')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['milk_subscription_id', 'delivery_date', 'shift'],
                'milk_deliveries_day_unique',
            );
            $table->index(['tenant_id', 'delivery_date'], 'milk_deliveries_date_idx');
            // "What is unbilled for this month" — the billing run's own query.
            $table->index(['tenant_id', 'invoice_id'], 'milk_deliveries_billing_idx');
        });
    }

    /*
    |--------------------------------------------------------------------------
    | 9. Freezers — ice cream
    |--------------------------------------------------------------------------
    |
    | A flavour is a product variant and a freezer is a warehouse location; both
    | already exist, and the plugin said so. What was missing was any way for a
    | parlour screen to know *which* locations are freezers, and what temperature
    | each is supposed to hold. Two columns, no new table — the alternative was a
    | freezers table duplicating a location's identity.
    */
    private function markFreezers(): void
    {
        Schema::table('warehouse_locations', function (Blueprint $table) {
            $table->boolean('is_freezer')->default(false)->after('type');
            /**
             * Target temperature in Celsius, negative for a freezer. Nullable
             * because an ambient rack has no target — and 0 would read as a
             * chiller set to freezing rather than as "not applicable".
             */
            $table->decimal('target_temp_c', 6, 2)->nullable()->after('is_freezer');
        });
    }

    /*
    |--------------------------------------------------------------------------
    | 10. Sales channels
    |--------------------------------------------------------------------------
    |
    | Cake orders, milk subscriptions and memberships all bill through the sales
    | spine, and each needs its own channel so the revenue is separable in
    | reporting. Read the live enum and append rather than restating the list:
    | six migrations have widened this column already and hard-coding it here
    | would silently drop whichever channel this file does not know about.
    */
    private function widenSalesChannels(): void
    {
        if (! $this->isMysql()) {
            return;
        }

        $this->setSalesChannels(array_values(array_unique([
            ...$this->salesChannels(),
            'cake',
            'milk',
            'membership',
        ])));
    }

    /*
    |--------------------------------------------------------------------------
    | 11. Stock movements
    |--------------------------------------------------------------------------
    |
    | A dairy's litres are stock like anything else: they come in off the morning
    | round and go out on the delivery boy's cycle. Two movement types so the
    | ledger says which, rather than both hiding inside 'purchase' and 'sale'
    | where a dairy could never separate its round from its counter.
    |
    | Read the live enum and append — several migrations have widened this column
    | already, including this plugin's own first one.
    */
    private function widenLedgerTypes(): void
    {
        if (! $this->isMysql()) {
            return;
        }

        $this->setLedgerTypes(array_values(array_unique([
            ...$this->ledgerTypes(),
            'milk_collect',
            'milk_delivery',
        ])));
    }

    public function down(): void
    {
        if ($this->isMysql()) {
            DB::table('stock_ledgers')->whereIn('type', ['milk_collect', 'milk_delivery'])->delete();
            $this->setLedgerTypes(array_values(array_diff(
                $this->ledgerTypes(),
                ['milk_collect', 'milk_delivery'],
            )));

            DB::table('sales_orders')->whereIn('channel', ['cake', 'milk', 'membership'])
                ->update(['channel' => 'standard']);
            $this->setSalesChannels(array_values(array_diff(
                $this->salesChannels(),
                ['cake', 'milk', 'membership'],
            )));
        }

        Schema::table('warehouse_locations', function (Blueprint $table) {
            $table->dropColumn(['is_freezer', 'target_temp_c']);
        });

        Schema::dropIfExists('milk_subscription_deliveries');
        Schema::dropIfExists('milk_subscriptions');
        Schema::dropIfExists('milk_collections');
        Schema::dropIfExists('milk_rate_charts');
        Schema::dropIfExists('cake_orders');

        Schema::table('restaurant_orders', function (Blueprint $table) {
            $table->dropUnique('restaurant_orders_token_unique');
            $table->dropColumn([
                'token_number', 'token_status', 'token_called_at',
                'token_collected_at', 'token_date',
            ]);
        });

        Schema::table('restaurant_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_membership_id');
        });

        Schema::dropIfExists('customer_memberships');
        Schema::dropIfExists('membership_plans');

        // The line's offer link has to go before the offers it points at.
        Schema::table('restaurant_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('menu_happy_hour_id');
        });

        Schema::dropIfExists('menu_happy_hour_items');
        Schema::dropIfExists('menu_happy_hours');
        Schema::dropIfExists('menu_combo_items');
        Schema::dropIfExists('menu_combos');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_combo');
        });
    }

    private function isMysql(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * The sales-order channel enum's current values, read from the live column.
     *
     * @return list<string>
     */
    private function salesChannels(): array
    {
        $column = DB::selectOne('SHOW COLUMNS FROM sales_orders WHERE Field = ?', ['channel']);
        preg_match_all("/'([^']+)'/", $column->Type, $matches);

        return $matches[1];
    }

    /**
     * The stock ledger's current type values, read from the live column.
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

    /** @param  list<string>  $channels */
    private function setSalesChannels(array $channels): void
    {
        $quoted = implode(',', array_map(static fn (string $c) => "'".$c."'", $channels));
        DB::statement(
            "ALTER TABLE sales_orders MODIFY channel ENUM({$quoted}) NOT NULL DEFAULT 'standard'",
        );
    }
};
