<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The counter's half of an alteration.
 *
 * `tailor_alterations` was built for the workroom: a piece comes back off a
 * production work order, a tailor redoes it, and the row records which work
 * order and which tailor. A retail garment shop raises the identical document
 * for a different reason — a customer bought a trouser and wants the hem taken
 * up by Friday — and the two differ only in what the alteration hangs off.
 *
 * So the retail case is four nullable columns here rather than a second
 * alteration register. A parallel table would have had to re-implement the
 * status flow, the numbering, the tailor assignment and the charge, and a shop
 * that does both (a boutique that stitches as well as sells) would then have
 * had its alterations split across two screens.
 *
 * Deliberately a core migration and not the Retail plugin's, even though the
 * garment trade is what motivated it: `tailor_alterations` is a core table that
 * core code reads, so a plugin owning its columns would break the tailoring
 * module the day the vertical was unregistered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tailor_alterations', function (Blueprint $table) {
            /**
             * Whose garment this is. Nullable because the workroom case has no
             * customer at all — the piece never left the building — and
             * nullOnDelete so removing a customer does not erase work that was
             * done and charged for.
             */
            $table->foreignId('customer_id')->nullable()->after('tailor_work_id')
                ->constrained('customers')->nullOnDelete();
            /**
             * The bill the garment was sold on. This is what makes a free
             * alteration defensible six months later ("we sold it, we hem it")
             * and what lets the shop find every alteration against a sale.
             */
            $table->foreignId('invoice_id')->nullable()->after('customer_id')
                ->constrained('invoices')->nullOnDelete();
            /**
             * Which size/colour, when the product has a matrix behind it.
             * `product_id` alone would say "Slim Fit Chino" and leave the tailor
             * to guess which of the six waists is on the hanger.
             */
            $table->foreignId('variant_id')->nullable()->after('product_id')
                ->constrained('product_variants')->nullOnDelete();
            /**
             * When it was promised. Distinct from completed_date, which is when
             * it was actually finished: the gap between the two is the only
             * measure of whether the shop keeps its word, and a single date
             * column cannot express a job that ran late.
             */
            $table->date('promised_date')->nullable()->after('reported_date');
        });

        Schema::table('tailor_alterations', function (Blueprint $table) {
            // The counter's working list: what is promised, soonest first.
            $table->index(['tenant_id', 'promised_date'], 'tailor_alterations_promised_idx');
            $table->index(['tenant_id', 'customer_id'], 'tailor_alterations_customer_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tailor_alterations', function (Blueprint $table) {
            $table->dropIndex('tailor_alterations_promised_idx');
            $table->dropIndex('tailor_alterations_customer_idx');
        });

        Schema::table('tailor_alterations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropConstrainedForeignId('invoice_id');
            $table->dropConstrainedForeignId('variant_id');
            $table->dropColumn('promised_date');
        });
    }
};
