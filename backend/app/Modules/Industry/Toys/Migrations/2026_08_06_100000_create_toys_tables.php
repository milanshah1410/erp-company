<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Toys & Games plugin schema: who a toy is for, and whether it may be sold.
 *
 * `.claude/shops-modules.md` §"🧸 9. Toy Shop": Age Category, Safety Category,
 * Barcode, Offers, Combo Sales, Gift Packaging.
 *
 * Four of those six already exist elsewhere and are deliberately not rebuilt
 * here — barcodes are `inventory`/`devices`, offers are the till's discounts,
 * and combo packs and gift wrapping are the Gift & Novelty module's, which the
 * toy-shop preset ticks (see ToysPlugin). What is left is the pair that belongs
 * to nobody else, and it is the pair that makes this a different trade from the
 * gift counter next door: the buyer is not the user, and the goods are regulated
 * because of it.
 *
 * Three groups of change:
 *
 *  1. toy_age_grades — the shop's own age bands. A catalogue rather than an enum
 *     because the bands are a merchandising decision that differs by shop and by
 *     supplier ("3+", "18m–3y", "8–12", "14+"), and because each band carries the
 *     words that have to be said out loud at the counter.
 *
 *  2. toy_safety_categories — what is physically dangerous about an article, the
 *     standard it is certified against, and the age below which it must not be
 *     sold at all.
 *
 *  3. toy_safety_certificates — the paperwork behind (2), and the only part of
 *     this vertical with a clock in it. In India a toy may not be sold without a
 *     live BIS licence under the Toys (Quality Control) Order; the licence
 *     belongs to the manufacturer, expires on a date nobody has in their diary,
 *     and nothing on the shelf looks any different the day after. Kept separate
 *     from (2) precisely because of that clock: a class is a permanent property
 *     of an article, a certificate is a dated document, and folding them together
 *     would either make the warning vanish when a licence lapsed or make the
 *     licence look permanent.
 *
 * Plus two nullable columns on `products`, which is where an article's band and
 * class are read from on every counter lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- 1. Age bands -----------------------------------------------------
        Schema::create('toy_age_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // "3PLUS", "18M-3Y" — short because it prints on a shelf label.
            $table->string('code', 40);
            $table->string('label', 80);

            /**
             * Months rather than years, and both ends, because the bands that
             * matter are measured in months at the bottom of the range: the
             * whole of the under-three rule turns on 36, and "0–18m" and
             * "18m–3y" are different shelves. `max_months` is nullable for the
             * open-ended top band ("14+").
             */
            $table->unsignedInteger('min_months')->default(0);
            $table->unsignedInteger('max_months')->nullable();

            /**
             * The sentence the counter says and the label prints. Held on the
             * band rather than assembled in code because it is a legal form of
             * words in some markets, and a shop told to change it must be able
             * to change it without a deployment.
             */
            $table->string('warning', 255)->nullable();
            // "An adult has to be watching" — different from a warning, and the
            // thing a grandparent buying a chemistry set needs told.
            $table->boolean('requires_supervision')->default(false);
            $table->text('description')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'toy_age_grades_code_unique');
            $table->index(['tenant_id', 'is_active', 'sort_order'], 'toy_age_grades_active_idx');
        });

        // --- 2. Safety classification ------------------------------------------
        Schema::create('toy_safety_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('label', 80);

            /**
             * The standard the class is written against. An enum rather than a
             * free string so a certificate can be matched to the class it
             * satisfies — a shop holding an EN 71 report for an article that
             * needs a BIS licence is not covered, and the two have to be
             * comparable for the counter to be able to say so.
             */
            $table->enum('standard', ['bis', 'en71', 'astm', 'iso8124', 'ce', 'none'])->default('bis');

            /**
             * What is physically dangerous about the article — small parts,
             * button cells, magnets, projectiles, cords, sharp edges, chemicals,
             * flammability. JSON because an article usually has more than one and
             * the list grows with every recall anyone reads about; a column per
             * hazard would be a migration every time.
             */
            $table->json('hazards')->nullable();
            $table->string('warning_text', 255)->nullable();

            /**
             * The age floor the classification itself imposes, independent of
             * whatever band the buyer chose. An article with small parts cannot
             * be sold as suitable for an eighteen-month-old however the age grade
             * is set, and this is what lets the counter catch that.
             */
            $table->unsignedInteger('min_age_months')->nullable();

            $table->boolean('requires_certificate')->default(true);
            /**
             * Whether a missing or lapsed certificate *stops* the sale rather
             * than merely warning about it.
             *
             * A switch and not a policy, because the honest answer differs by
             * shop and by item: a mandatory-certification toy sold without a live
             * BIS licence is an offence and the till should refuse it, but a shop
             * mid-way through capturing five thousand articles cannot have its
             * counter shut down by data entry. Default off for exactly that
             * reason — a shop turns it on when its register is trustworthy.
             */
            $table->boolean('blocks_sale_without_certificate')->default(false);

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'toy_safety_categories_code_unique');
        });

        // --- 3. The paperwork behind it ----------------------------------------
        Schema::create('toy_safety_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('certificate_number', 80);
            $table->enum('standard', ['bis', 'en71', 'astm', 'iso8124', 'ce', 'other'])->default('bis');

            /**
             * Scope. A licence almost never covers one SKU: it is issued to a
             * manufacturer for a range, which is why all three of these are
             * nullable and any combination is legal. The narrowest match wins
             * when the counter looks one up — product, then brand, then supplier
             * — so a specific test report overrides a blanket licence rather than
             * competing with it.
             */
            $table->foreignId('product_id')->nullable()->constrained('products')->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->cascadeOnDelete();

            // Who issued it, and the manufacturer's own licence reference — the
            // two things an inspector asks for and a portal is searched on.
            $table->string('issued_by', 120)->nullable();
            $table->string('licence_no', 80)->nullable();

            $table->date('issued_on')->nullable();
            /**
             * Nullable for the genuinely open-ended declaration (a CE
             * self-declaration does not expire), but the common case has a date
             * and it is the whole reason this table exists. Indexed because the
             * expiring-soon sweep is the screen the register is opened for.
             */
            $table->date('expires_on')->nullable();

            // A scan of the certificate, on the tenant's own folder — see
            // App\Support\TenantStorage. Path only; the file is private.
            $table->string('document_path', 255)->nullable();

            /**
             * `expired` is never written by the application — expiry is derived
             * from the date on every read, for the same reason the warranty
             * registers derive theirs: a row can sit at 'active' while its date
             * quietly passed last week. The stored state exists for the one case
             * a date cannot express, which is a licence withdrawn before its time.
             */
            $table->enum('status', ['active', 'revoked'])->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'expires_on'], 'toy_certificates_expiry_idx');
            $table->index(['tenant_id', 'product_id'], 'toy_certificates_product_idx');
            $table->index(['tenant_id', 'brand_id'], 'toy_certificates_brand_idx');
        });

        // --- 4. The link onto the article ---------------------------------------
        Schema::table('products', function (Blueprint $table) {
            /**
             * Who the article is for, and what is dangerous about it. Two
             * nullable columns on the product rather than a join table: an
             * article has exactly one age band and one safety class, both are
             * read on every counter lookup, and a workspace without the Toys
             * module simply never fills them in.
             */
            $table->foreignId('toy_age_grade_id')->nullable()
                ->constrained('toy_age_grades')->nullOnDelete();
            $table->foreignId('toy_safety_category_id')->nullable()
                ->constrained('toy_safety_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('toy_safety_category_id');
            $table->dropConstrainedForeignId('toy_age_grade_id');
        });

        Schema::dropIfExists('toy_safety_certificates');
        Schema::dropIfExists('toy_safety_categories');
        Schema::dropIfExists('toy_age_grades');
    }
};
