<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Beauty & Personal Care plugin schema: which shade it is, and how long it is
 * still good for.
 *
 * `.claude/shops-modules.md` §"💄 13. Cosmetic Store": Batch, Expiry,
 * Shade/Color, Barcode, Offers, Membership.
 *
 * Three of those six are already somebody else's and are deliberately not
 * rebuilt here — barcodes are `inventory`/`devices`, offers and membership are
 * core Loyalty & Offers (the same club card a café or a supermarket sells), and
 * the batch register with its expiry date is core `batches`, which every trade
 * that stocks a dated good already runs on.
 *
 * What is left is the pair that makes this a different shop from the chemist or
 * the gift counter, and it is the pair nothing in core can hold:
 *
 *  1. cosmetic_shades — a shade is not a colour. A garment colour is a word
 *     ("Navy") attached to a size run; a cosmetic shade is an identity a
 *     customer knows by number and will not substitute — "NC42", "015 Nude
 *     Blush" — and it carries the four things that decide whether it suits the
 *     person holding it: family, finish, undertone and depth. A foundation range
 *     is forty of them and a shop's whole stock problem is which forty-first of
 *     it is missing. So it is a master with a swatch, not a string in a JSON
 *     column, and the shade card generates ordinary `product_variants` from it
 *     (see ShadeCardService) precisely so nothing downstream has to learn a
 *     second word for a variant.
 *
 *  2. cosmetic_open_units — the second clock. Core knows one date, the printed
 *     expiry on the batch, and for make-up that is the less important of the
 *     two: a mascara is finished six months after it is *opened*, whatever the
 *     carton says, and the units that get opened in a cosmetics shop are the
 *     testers sitting on the counter being used by strangers all day. Nothing in
 *     an ERP has a place for stock that has left the shelf, will never be sold,
 *     and dies on a date computed from the day somebody broke the seal. That is
 *     this table.
 *
 * Plus the columns those two are read through: the shade on a product and on a
 * variant, and the period-after-opening on the product it was printed on.
 *
 * The expiry *policy* — how many days of remaining life a counter insists on,
 * and whether short-dated stock is refused or merely flagged — is deliberately
 * not here. It is three tenant settings read by ShelfLifeService, the same shape
 * Distribution's credit enforcement uses, because it is one shop-wide decision
 * an owner changes twice a year and not a per-article property somebody has to
 * key five thousand times.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- 1. The shade master ------------------------------------------------
        Schema::create('cosmetic_shades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            /**
             * The number on the pack. Short because it prints on a shelf label
             * and because it is what a customer says out loud at the counter —
             * they ask for "015", not for "Nude Blush".
             */
            $table->string('code', 40);
            $table->string('name', 80);

            /**
             * Whose shade it is. Nullable, and the reason it exists: shade codes
             * are only unique inside a brand. Two houses both number a foundation
             * "015" and they are nothing like each other, so a shade master
             * without the brand on it collapses them into one row and puts the
             * wrong bottle in the bag.
             */
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();

            /**
             * The colour family the shade sits in — nude, pink, red, berry,
             * brown, coral, plum. A free string rather than an enum because it
             * is how the shop groups its own display, it differs by counter, and
             * a shade card that cannot say "mauve" because nobody put it in an
             * enum is a shade card the staff stop using.
             */
            $table->string('family', 40)->nullable();

            /**
             * Finish and undertone, which *are* enums, because unlike family
             * they are a small closed vocabulary the whole trade shares and they
             * are what a customer is actually choosing between. Two people asking
             * for "a nude lipstick" want a matte and a gloss and will each return
             * the other one.
             */
            $table->enum('finish', [
                'matte', 'satin', 'cream', 'gloss', 'shimmer', 'metallic', 'sheer', 'other',
            ])->default('matte');
            $table->enum('undertone', ['warm', 'cool', 'neutral', 'olive', 'unknown'])->default('unknown');

            /**
             * How deep the shade is, fair (1) to deep (20). Only base make-up
             * uses it — foundation, concealer, compact — which is why it is
             * nullable, and it is the axis a shade card is *sorted* on: a
             * foundation range read in any order but light-to-dark is unusable.
             */
            $table->unsignedTinyInteger('depth_level')->nullable();

            // The swatch. Approximate by nature, and worth having anyway: a grid
            // of forty shade names with no colour in it tells nobody anything.
            $table->string('hex', 7)->nullable();

            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            /**
             * Scoped by brand, so "015" may exist once per house.
             *
             * MySQL treats NULLs as distinct in a unique index, so this does not
             * constrain brandless shades at all — two rows may both be
             * (tenant, NULL, '015'). That gap is closed in ShadeRequest, which
             * checks the brandless case explicitly rather than being left to a
             * constraint that quietly does not apply.
             */
            $table->unique(['tenant_id', 'brand_id', 'code'], 'cosmetic_shades_code_unique');
            $table->index(['tenant_id', 'is_active', 'sort_order'], 'cosmetic_shades_active_idx');
            $table->index(['tenant_id', 'family'], 'cosmetic_shades_family_idx');
        });

        // --- 2. Opened stock: testers and the PAO clock -------------------------
        Schema::create('cosmetic_open_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            /**
             * The batch it came out of, where the shop tracks batches. Nullable
             * because plenty of cosmetics counters do not, and a tester whose
             * batch is unknown is still a tester with a PAO date — refusing to
             * record it would only mean it is not recorded anywhere.
             */
            $table->foreignId('batch_id')->nullable()->constrained('batches')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();

            /**
             * Why the seal was broken. All four leave saleable stock and none of
             * them is a sale, but they are written off differently and a shop
             * that cannot tell them apart cannot tell shrinkage from marketing:
             * a tester is the cost of trading, a staff sample is a benefit, and
             * a quality check is a supplier conversation.
             */
            $table->enum('purpose', ['tester', 'demo', 'staff', 'quality'])->default('tester');

            $table->decimal('quantity', 15, 3)->default(1);

            $table->date('opened_on');

            /**
             * Both snapshotted at the moment of opening, not read live.
             *
             * The PAO is copied off the product because it is a property of the
             * pack that was opened, and a buyer correcting the product master
             * next month ("it is 6M, not 12M") must not silently move the death
             * date of a tester that has been on the counter since March.
             * expires_on is stored rather than derived for the same reason and
             * one more: the whole register is queried by it, and a computed date
             * cannot be indexed.
             */
            $table->unsignedSmallInteger('pao_months')->nullable();
            $table->date('expires_on')->nullable();

            /**
             * open → the unit is in use. finished / discarded are the two ways it
             * ends, and they are not the same event: finished is a tester used up
             * as intended, discarded is one thrown away with product still in it
             * because the PAO ran out. The difference is the number a counter
             * manager is judged on.
             */
            $table->enum('status', ['open', 'finished', 'discarded'])->default('open');
            $table->date('closed_on')->nullable();

            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            // The board's query: what is open, and what dies soonest.
            $table->index(['tenant_id', 'status', 'expires_on'], 'cosmetic_open_units_due_idx');
            $table->index(['tenant_id', 'product_id'], 'cosmetic_open_units_product_idx');
        });

        // --- 3. What the counter reads it all through ---------------------------
        Schema::table('products', function (Blueprint $table) {
            /**
             * The shade of an article keyed one-SKU-per-shade. Both this and the
             * variant column below exist because both shop styles are real: a
             * chain keys one product per range and forty variants under it, and
             * a single-counter shop keys forty products. CosmeticsPlugin's
             * resolver reads the variant first and falls back to here, so
             * everything downstream asks one question regardless.
             */
            $table->foreignId('cosmetic_shade_id')->nullable()->after('toy_safety_category_id')
                ->constrained('cosmetic_shades')->nullOnDelete();

            /**
             * Period after opening, in months — the "6M" in the little open-jar
             * symbol on the back of the pack. A property of the formulation, so
             * it lives on the product and is copied onto each unit as it is
             * opened.
             */
            $table->unsignedSmallInteger('pao_months')->nullable()->after('cosmetic_shade_id');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->foreignId('cosmetic_shade_id')->nullable()->after('attributes')
                ->constrained('cosmetic_shades')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropForeign(['cosmetic_shade_id']);
            $table->dropColumn('cosmetic_shade_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['cosmetic_shade_id']);
            $table->dropColumn(['cosmetic_shade_id', 'pao_months']);
        });

        Schema::dropIfExists('cosmetic_open_units');
        Schema::dropIfExists('cosmetic_shades');
    }
};
