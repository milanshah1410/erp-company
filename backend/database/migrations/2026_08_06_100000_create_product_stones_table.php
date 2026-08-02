<?php

declare(strict_types=1);

use App\Services\JewelleryPricingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jewellery: the stone register, and the hallmark the piece carries.
 *
 * `products.stone_value` already existed as one number — "the stones in this
 * piece are worth ₹40,000". That is enough to bill an ornament and not nearly
 * enough to sell one. A customer buying a diamond ring is buying four specific
 * things (carat, cut, colour, clarity) and the certificate that attests to
 * them, and a shop that cannot print those on the bill cannot argue the price.
 * Studded jewellery is also *valued* per stone: 0.75 ct of VS1/F at ₹2.4 lakh a
 * carat plus eight 0.02 ct side stones is a build-up, not a lump sum.
 *
 * So stones become lines, and the single number on the product goes on being
 * the total — recalculated from the lines when there are any, typed by hand
 * when there are not. Nothing downstream had to learn about stones for the
 * money to stay right: {@see JewelleryPricingService} still adds
 * `products.stone_value` to the build-up exactly as before.
 *
 * Hallmarking lands here too. The HUID column shipped with the original
 * jewellery migration, but a HUID on its own is a string with no provenance —
 * the register wants the centre that struck it and the date it was struck, and
 * the bill wants the ₹45-odd per piece the centre charged, which is a real line
 * on a real jewellery invoice and was being absorbed silently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_stones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            /**
             * 'diamond' is not just another stone: it is the only one the four
             * grade columns below mean anything for, and the only one that
             * routinely arrives with a lab certificate. Everything else is a
             * coloured stone valued on weight and eye.
             */
            $table->enum('stone_type', [
                'diamond', 'ruby', 'emerald', 'sapphire', 'pearl',
                'cz', 'semi_precious', 'other',
            ])->default('diamond');
            // 'Round Brilliant', 'Princess', 'Pear'… free text: the trade names
            // more shapes than any enum would survive.
            $table->string('shape', 40)->nullable();
            $table->unsignedSmallInteger('pieces')->default(1);
            // Total carats for the line, not per stone. 8 side stones of 0.02 ct
            // are one line of 0.16 ct — which is how they are bought and billed.
            $table->decimal('carat_weight', 10, 3)->default(0);

            /**
             * The four Cs. Free strings rather than enums on purpose: GIA, IGI
             * and the domestic labs do not agree on a vocabulary, and a shop that
             * writes "SI1" where another writes "SI-1" should not be corrected by
             * its billing software.
             */
            $table->string('cut_grade', 30)->nullable();
            $table->string('colour_grade', 30)->nullable();
            $table->string('clarity_grade', 30)->nullable();

            /**
             * How the line is priced. Diamonds go per carat; a strand of pearls
             * or a set of matched CZ goes per piece; a bought-in cabochon has one
             * price and no arithmetic behind it.
             */
            $table->enum('rate_type', ['per_carat', 'per_piece', 'flat'])->default('per_carat');
            $table->decimal('rate', 15, 2)->default(0);
            // Derived from rate_type × rate, but stored: the shop is allowed to
            // overrule the arithmetic on a piece it bought as a job lot, and the
            // total on the product has to add up to what is actually charged.
            $table->decimal('value', 15, 2)->default(0);

            // The certificate. Its number is what a customer checks online, so it
            // is indexed rather than buried in notes.
            $table->string('certificate_number', 60)->nullable();
            $table->string('certifying_lab', 40)->nullable();   // GIA, IGI, HRD, SGL…
            $table->date('certificate_date')->nullable();

            $table->string('setting_type', 40)->nullable();     // prong, bezel, pave…
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'product_id']);
            $table->index(['tenant_id', 'certificate_number']);
        });

        Schema::table('products', function (Blueprint $table) {
            // BIS assaying centre that struck the mark, and when.
            $table->string('hallmark_centre', 120)->nullable()->after('huid');
            $table->date('hallmarked_on')->nullable()->after('hallmark_centre');
            /**
             * What the centre charged for the mark. Per piece and flat — it is
             * not a percentage of anything — and it defaults to 0, so a shop that
             * absorbs it goes on absorbing it and no existing bill changes.
             */
            $table->decimal('hallmark_charge', 10, 2)->default(0)->after('hallmarked_on');
        });

        // The rest of the build-up the sale was billed at. `stone_details` is a
        // snapshot rather than a join: the register is a live master a jeweller
        // re-grades and re-prices, and last year's certificate must keep saying
        // what it said when the customer signed for it.
        Schema::table('sales_items', function (Blueprint $table) {
            $table->decimal('hallmark_charge', 15, 2)->nullable()->after('stone_value');
            $table->json('stone_details')->nullable()->after('hallmark_charge');
        });
    }

    public function down(): void
    {
        Schema::table('sales_items', function (Blueprint $table) {
            $table->dropColumn(['hallmark_charge', 'stone_details']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['hallmark_centre', 'hallmarked_on', 'hallmark_charge']);
        });

        Schema::dropIfExists('product_stones');
    }
};
