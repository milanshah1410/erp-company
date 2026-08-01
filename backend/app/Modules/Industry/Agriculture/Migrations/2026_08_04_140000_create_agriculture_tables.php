<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agriculture plugin schema: the dealer's licence register and agri attributes,
 * and the farm's herd, flocks and daily production.
 *
 * Four groups of change:
 *
 *  1. agri_licences — a dealer may not legally sell a seed, a fertiliser or an
 *     insecticide without a current licence for that input, issued for those
 *     premises. Expiry is the whole point of the record: it creeps up during a
 *     season, and trading a day past it is prosecutable. Modelled as its own
 *     table rather than settings rows because a shop holds several at once, each
 *     with its own number, authority and date.
 *
 *  2. Agri attributes on products — crop, variety, technical name, nutrient
 *     grade, germination. On the product row for the same reason F&B put menu
 *     attributes there: the thing a farmer asks for by crop, the thing the
 *     counter bills and the thing an inspector checks must be one record.
 *
 *  3. livestock_animals / livestock_flocks — a herd is tracked per head (a cow
 *     has a name, a lactation number and a yield of her own) and birds are
 *     tracked per batch (5,000 chicks are one placement, not 5,000 records).
 *     Two tables because they are genuinely two shapes; forcing one would leave
 *     every dairy row carrying null bird counts.
 *
 *  4. farm_production_logs — the daily entry that makes the farm real to the
 *     ERP. A farm's output is *created*, not purchased, so nothing exists in
 *     inventory until this row posts it; and feed — the cost that decides
 *     whether a farm makes money — leaves the store every day whether or not
 *     anything sells. One row therefore carries both directions plus the
 *     mortality that shrinks a flock.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- 1. The dealer's statutory licences -----------------------------
        Schema::create('agri_licences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            /**
             * Which trade the licence authorises. Separate licences under
             * separate Acts — Seeds Act, Fertiliser (Control) Order, Insecticides
             * Act — which is why an agro centre selling all three holds three.
             */
            $table->enum('licence_type', ['seed', 'fertilizer', 'insecticide', 'other']);
            $table->string('licence_number', 100);
            // Form the licence was granted on (Form O / Form V / Form 'B'), as
            // printed. Free text: the forms differ by state and get renamed.
            $table->string('form_number', 50)->nullable();
            // Issuing authority — the district agriculture officer, usually.
            $table->string('authority')->nullable();
            $table->string('holder_name')->nullable();
            /**
             * A licence authorises a *place*, not a company: a second shop needs
             * its own. Stored as text because it must match the licence exactly,
             * character for character, not the tenant's current address record.
             */
            $table->text('premises')->nullable();
            $table->date('issued_on')->nullable();
            /**
             * The date the shop must stop selling. Nullable for the handful of
             * licences granted in perpetuity — a null here means "no expiry",
             * never "unknown", so the expiry report can trust it.
             */
            $table->date('valid_till')->nullable();
            /**
             * Only the states a person puts a licence into. Expiry is *derived*
             * from valid_till on every read instead of stored: a stored 'expired'
             * would need a nightly job to stay true, and would be wrong in
             * between.
             */
            $table->enum('status', ['active', 'suspended', 'surrendered'])->default('active');
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'licence_type', 'licence_number'], 'agri_licences_number_unique');
            $table->index(['tenant_id', 'valid_till'], 'agri_licences_expiry_idx');
        });

        // --- 2. Agri attributes on the product master -----------------------
        Schema::table('products', function (Blueprint $table) {
            /**
             * Which controlled trade this item belongs to, and therefore which
             * licence covers selling it. Nullable because most of a catalogue is
             * not a controlled input at all — even in an agro centre, which also
             * sells buckets and sprayers.
             */
            $table->enum('agri_input_type', ['seed', 'fertilizer', 'pesticide', 'feed', 'equipment', 'other'])
                ->nullable()->after('manufacturer');
            /**
             * The crop it is for — cotton, wheat, groundnut. What a farmer
             * actually walks in and names, and how a dealer's stock is mentally
             * organised during a season.
             */
            $table->string('crop', 100)->nullable()->after('agri_input_type');
            // Seed variety / hybrid name, or a fertiliser's brand grade.
            $table->string('variety', 100)->nullable()->after('crop');
            /**
             * The active ingredient and formulation — "Imidacloprid 17.8% SL".
             * For a pesticide this, not the brand, is the thing a farmer asks for
             * and an inspector checks, and it is how two competing brands are
             * recognised as the same chemical.
             */
            $table->string('technical_name')->nullable()->after('variety');
            // Nutrient grade as printed: "12:32:16", "46% N". A string because
            // the label is what must be reproducible, not a parsed NPK triple.
            $table->string('nutrient_content', 100)->nullable()->after('technical_name');
            // Germination percentage printed on a seed packet.
            $table->decimal('germination_percent', 5, 2)->nullable()->after('nutrient_content');
        });

        // --- 3a. The herd, per head ------------------------------------------
        Schema::create('livestock_animals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // The ear tag. The farm's own identifier and the only one staff use.
            $table->string('tag_number', 50);
            $table->string('name', 100)->nullable();
            $table->enum('species', ['cow', 'buffalo', 'goat', 'sheep', 'other'])->default('cow');
            $table->string('breed', 100)->nullable();
            $table->enum('gender', ['female', 'male'])->default('female');
            $table->date('date_of_birth')->nullable();
            $table->date('acquired_on')->nullable();
            $table->enum('acquisition_type', ['born', 'purchased'])->default('purchased');
            $table->decimal('acquisition_cost', 15, 2)->default(0);
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            /**
             * The shed, as a warehouse. Production defaults to landing here, so
             * a two-site farm's milk does not silently pool into one store.
             */
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            /**
             * Parentage as tags rather than foreign keys: the dam may have been
             * sold years ago and the sire is usually a semen straw from a
             * co-operative, so neither is reliably a row on this farm.
             */
            $table->string('dam_tag', 50)->nullable();
            $table->string('sire_tag', 50)->nullable();
            $table->unsignedSmallInteger('lactation_number')->nullable();
            /**
             * 'dry' is not 'inactive': a dry cow is a productive asset between
             * lactations, still eating feed and still on the farm. Conflating it
             * with sold/dead would make the herd count meaningless.
             */
            $table->enum('status', ['active', 'dry', 'sold', 'dead', 'culled'])->default('active');
            $table->date('exited_on')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'tag_number'], 'livestock_animals_tag_unique');
            $table->index(['tenant_id', 'status'], 'livestock_animals_status_idx');
        });

        // --- 3b. Birds, per batch --------------------------------------------
        Schema::create('livestock_flocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('batch_code', 50);
            $table->enum('bird_type', ['broiler', 'layer', 'breeder', 'duck', 'other'])->default('broiler');
            $table->string('breed', 100)->nullable();
            $table->date('placed_on');
            // Day-old chicks placed. Never changes — it is the denominator of
            // every performance figure the farm is judged on.
            $table->unsignedInteger('opening_birds');
            /**
             * Birds alive now. Denormalised on purpose: every screen shows it,
             * and re-deriving it from the whole log history on each read would
             * make the flock list a table scan. FarmProductionService owns it and
             * moves it only when a log posts or unposts.
             */
            $table->unsignedInteger('current_birds')->default(0);
            $table->unsignedInteger('mortality_birds')->default(0);
            $table->unsignedInteger('culled_birds')->default(0);
            $table->unsignedInteger('sold_birds')->default(0);
            // Total cost of the placement, so cost per bird is knowable.
            $table->decimal('chick_cost', 15, 2)->default(0);
            // The hatchery.
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            // The shed. Eggs land here; feed is drawn from here.
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            // Roll-ups from the daily log, same ownership rule as current_birds.
            $table->decimal('feed_consumed_kg', 15, 3)->default(0);
            $table->unsignedBigInteger('eggs_collected')->default(0);
            $table->enum('status', ['active', 'closed'])->default('active');
            $table->date('closed_on')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'batch_code'], 'livestock_flocks_code_unique');
            $table->index(['tenant_id', 'status'], 'livestock_flocks_status_idx');
        });

        // --- 4. The daily production log -------------------------------------
        Schema::create('farm_production_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('log_date');
            /**
             * Cows are milked twice; a shed is collected once. 'day' covers the
             * single-collection case rather than forcing poultry to pretend it
             * has a morning shift.
             */
            $table->enum('session', ['morning', 'evening', 'day'])->default('morning');
            /**
             * Exactly one of these is set — an animal produced it, or a flock
             * did. Enforced in the request rather than by the schema, because no
             * portable check constraint expresses "exactly one of two columns"
             * across the drivers this app runs on.
             */
            $table->foreignId('animal_id')->nullable()->constrained('livestock_animals')->cascadeOnDelete();
            $table->foreignId('flock_id')->nullable()->constrained('livestock_flocks')->cascadeOnDelete();
            // What was produced: milk, eggs, manure. A stocked product, so the
            // farm sells its output through the ordinary sales spine.
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->decimal('quantity', 15, 3);
            /**
             * What the produce enters stock at. Farm costing is a season-long
             * argument (feed, labour, depreciation, the calf); rather than
             * inventing a number, this is entered and defaults to the product's
             * cost price.
             */
            $table->decimal('unit_cost', 15, 4)->default(0);
            // Fat and SNF decide the price of milk at every dairy in India, so
            // they belong on the yield that will be sold, not in a remark.
            $table->decimal('fat_percent', 5, 2)->nullable();
            $table->decimal('snf_percent', 5, 2)->nullable();
            /**
             * Feed drawn for this session. On the same row as the output because
             * that pairing *is* the farm's unit economics — recording them apart
             * is how a farm ends up knowing its yield and not its cost.
             */
            $table->foreignId('feed_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->decimal('feed_quantity', 15, 3)->default(0);
            // Birds lost, and birds taken out for sale as live weight.
            $table->unsignedInteger('mortality_count')->default(0);
            $table->unsignedInteger('culled_count')->default(0);
            /**
             * When this row's stock movements and flock counters were applied.
             * The idempotency guard: posting checks it and returns, so a
             * double-tap on a bad connection cannot deplete feed twice. Same
             * shape as ManufacturingOrder::$materials_consumed, kept as a
             * timestamp because "when" is worth knowing on a farm entry that
             * gets corrected the next morning.
             */
            $table->timestamp('posted_at')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'log_date'], 'farm_production_logs_date_idx');
            $table->index(['tenant_id', 'animal_id', 'log_date'], 'farm_production_logs_animal_idx');
            $table->index(['tenant_id', 'flock_id', 'log_date'], 'farm_production_logs_flock_idx');
        });

        if ($this->isMysql()) {
            // Produce coming off the farm, and feed going into it. Read the live
            // column rather than restating the list: several migrations have
            // already widened this enum and hard-coding would drop the values
            // this file does not know about.
            $types = $this->ledgerTypes();
            $types[] = 'farm_produce';
            $types[] = 'feed_consume';
            $this->setLedgerTypes(array_values(array_unique($types)));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('farm_production_logs');
        Schema::dropIfExists('livestock_flocks');
        Schema::dropIfExists('livestock_animals');

        if ($this->isMysql()) {
            DB::table('stock_ledgers')->whereIn('type', ['farm_produce', 'feed_consume'])->delete();
            $this->setLedgerTypes(array_values(array_diff(
                $this->ledgerTypes(),
                ['farm_produce', 'feed_consume'],
            )));
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'agri_input_type', 'crop', 'variety',
                'technical_name', 'nutrient_content', 'germination_percent',
            ]);
        });

        Schema::dropIfExists('agri_licences');
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
