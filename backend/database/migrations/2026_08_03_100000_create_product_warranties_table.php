<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The retail warranty register: the card a counter issues against a sale, and
 * the claims made back against it.
 *
 * Deliberately separate from `automobile_warranties`, which already exists and
 * is not the same record. That one hangs off a *vehicle* and runs on two clocks
 * at once — three years or 45,000 km, whichever comes first — because that is
 * how motor cover is written. A footwear, watch or appliance warranty has no
 * odometer and no vehicle; it hangs off the item that was sold, on the bill it
 * was sold on, and it is claimed by the customer walking back in with the shoe.
 * Merging the two would have put a kilometre limit on a pair of sandals and a
 * VIN column on an electric kettle.
 *
 * Three parts:
 *
 *  1. products.warranty_months / warranty_terms — the *policy*. "Six months on
 *     the sole" is a property of the article, not of any one sale, so it is
 *     stored once on the product and copied onto each card at issue. Copied,
 *     not referenced: shortening next season's policy must not retroactively
 *     cancel cover a customer already holds.
 *
 *  2. product_warranties — the *cards*, one per item actually covered. Issued
 *     from an invoice rather than automatically on every sale: a shop selling
 *     four hundred pairs a week does not want four hundred rows, it wants the
 *     ones a customer asked to be registered. See ProductWarrantyController.
 *
 *  3. product_warranty_claims — what came back. A claim here really is its own
 *     record, which is where this parts company with the Automobile register:
 *     there, a claim *is* a job card and counting them was enough. A footwear
 *     shop does not open a job card to swap a pair — it inspects, decides
 *     repair/replace/refund/reject, and that decision (and what honouring it
 *     cost) is the only trace the claim ever leaves.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- 1. The policy, on the article ----------------------------------
        Schema::table('products', function (Blueprint $table) {
            /**
             * Zero and null are different answers and both are kept: null means
             * "nobody has said", 0 means "explicitly sold without cover". Only a
             * positive value offers to issue a card.
             */
            $table->unsignedSmallInteger('warranty_months')->nullable()->after('is_service');
            // What the cover actually promises, in the shop's own words. Printed
            // on the card, and the default for every card issued on this article.
            $table->text('warranty_terms')->nullable()->after('warranty_months');
        });

        // --- 2. The cards ----------------------------------------------------
        Schema::create('product_warranties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('warranty_number', 40);

            /**
             * Nullable so a card can be issued at the counter before the buyer's
             * details are taken — and nullOnDelete because erasing a customer
             * must not erase the fact that cover was given.
             */
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            /**
             * The variant actually handed over. This is the column footwear
             * needs and a single-SKU trade never fills: "the black pair, size 8"
             * is what walked out of the shop, and a claim on a size 8 that the
             * register recorded as a size 11 is an argument nobody can win.
             */
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('serial_no', 100)->nullable();

            // What it was sold on, so a claim traces back to the money.
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            /**
             * Who actually stands behind it. It decides where a claim goes, not
             * merely how the card reads: `manufacturer` cover is passed up the
             * chain, `shop` cover comes out of this shop's own pocket, and
             * `extended` was sold as a separate line on the bill.
             */
            $table->enum('source', ['manufacturer', 'shop', 'extended'])->default('manufacturer');

            $table->date('start_date');
            $table->date('end_date');
            // Kept alongside the dates rather than derived from them: a card
            // reissued after a replacement states its own term, and "6 months"
            // is what the customer was told.
            $table->unsignedSmallInteger('coverage_months')->nullable();

            /**
             * `claimed` is terminal here and means the covered item is gone —
             * replaced or refunded. That is the opposite of the Automobile
             * register, where a claim does not use cover up, and the difference
             * is real: a car comes back for a second, unrelated fault, but the
             * pair of shoes that was swapped no longer exists to fail again.
             */
            $table->enum('status', ['active', 'expired', 'claimed', 'void'])->default('active');
            $table->unsignedInteger('claims_count')->default(0);
            $table->timestamp('last_claim_at')->nullable();

            // Snapshot of the article's policy text at the moment of issue.
            $table->text('terms')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'warranty_number'], 'product_warranties_number_unique');
            // The counter lookup: a customer arrives with a bill, or with the
            // box and its serial, and rarely with the card.
            $table->index(['tenant_id', 'invoice_id'], 'product_warranties_invoice_idx');
            $table->index(['tenant_id', 'customer_id'], 'product_warranties_customer_idx');
            $table->index(['tenant_id', 'serial_no'], 'product_warranties_serial_idx');
            // "What runs out this month" — the register read as a worklist.
            $table->index(['tenant_id', 'status', 'end_date'], 'product_warranties_expiry_idx');
        });

        // --- 3. The claims ---------------------------------------------------
        Schema::create('product_warranty_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('claim_number', 40);
            // cascadeOnDelete: a claim has no meaning without the cover it was
            // made against, and the cover itself refuses deletion once claimed.
            $table->foreignId('warranty_id')->constrained('product_warranties')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            $table->date('reported_on');
            $table->text('fault');

            /**
             * open → (approved | rejected) → resolved. Assessment is a state of
             * its own because the item is usually left with the shop while
             * someone who knows soles from stitching looks at it, and the
             * counter needs to be able to say "we have it, we have not decided".
             */
            $table->enum('status', ['open', 'assessed', 'approved', 'rejected', 'resolved'])->default('open');
            /**
             * What was actually done. Null until decided; `rejected` is carried
             * here as well as in `status` so a closed claim states its outcome
             * in one column whichever way it went.
             */
            $table->enum('resolution', ['repair', 'replace', 'refund', 'rejected'])->nullable();
            $table->text('resolution_note')->nullable();
            $table->date('resolved_on')->nullable();

            /**
             * What honouring it cost this shop. The number the owner actually
             * wants: an article whose warranty cost outruns its margin is a
             * buying decision, and without this column that is invisible.
             */
            $table->decimal('cost', 15, 2)->default(0);
            // The replacement bill or credit note, when one was raised. A link,
            // not a posting — the money is made by the sales spine as usual.
            $table->foreignId('settlement_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'claim_number'], 'warranty_claims_number_unique');
            $table->index(['tenant_id', 'warranty_id'], 'warranty_claims_warranty_idx');
            // The open-claims desk, which is the only list this table is read as.
            $table->index(['tenant_id', 'status', 'reported_on'], 'warranty_claims_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_warranty_claims');
        Schema::dropIfExists('product_warranties');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['warranty_months', 'warranty_terms']);
        });
    }
};
