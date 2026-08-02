<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mobile plugin schema: the IMEI register, the insurance book, the finance file
 * and the part-exchange register.
 *
 * The trade's defining feature is that it sells *units*, not quantities. A shop
 * does not sell three Galaxy A55s; it sells three specific handsets, and for the
 * next two years every conversation about one of them concerns that handset and
 * no other — is it covered, how many EMIs are left, what did we allow for the old
 * one, has it been opened before. So the handset is the master record and the
 * customer hangs off it, exactly as the vehicle does in Automobile and the unit
 * does in Electronics.
 *
 * **There is deliberately no warranty table here.** The core `warranty` module's
 * `product_warranties` is already the register a claim is settled against, and a
 * second one would leave a phone counter with two warranty screens that disagree.
 * This migration adds the one column that register was missing for goods
 * identified by IMEI — see group 2.
 *
 * Five groups of change:
 *
 *  1. mobile_handsets — one row per physical handset the shop has sold or is
 *     holding. Separate from core `serial_numbers` for the same reason Electronics
 *     keeps its own: that table is an inventory device (which serial sits in which
 *     warehouse, what document moved it) and is consumed by stock. This one is an
 *     *aftersales* record, and it outlives the stock movement that created it —
 *     including for handsets this shop never sold, which is most of the repair and
 *     insurance business.
 *
 *  2. product_warranties.handset_id. Gives a warranty card a handset rather than
 *     only a typed-in serial, which is what makes "is this covered?" answerable
 *     from a scanner at the counter.
 *
 *  3. mobile_insurance_policies — the cover a warranty is not. A warranty promises
 *     the phone was not faulty; insurance pays when the customer drops it, drowns
 *     it or has it stolen. It is written by an underwriter, sold for a premium,
 *     carries an excess per claim and — the part that decides arguments — is
 *     usually finished after one claim. None of that fits a warranty card, and
 *     pretending it did would have a counter promising a second screen replacement
 *     that nobody is going to pay for.
 *
 *  4. mobile_emi_plans / mobile_emi_instalments — the finance file behind a sale
 *     and the schedule it runs on. The distinction that earns its column is who
 *     the customer owes: a finance company that already settled with the shop, or
 *     the shop itself.
 *
 *  5. mobile_buybacks — the old handset taken in part-exchange. Modelled on
 *     `old_gold_receipts`, because it is the same act in a different trade:
 *     something of the customer's is valued, taken in, held as an open credit and
 *     set off against a bill — as a tender, not a discount.
 *
 * Plus the columns that turn a service job into a repair job card. A repair *is* a
 * service job with a handset and possibly a policy behind it; a parallel job book
 * would have to re-implement parts issue and invoicing before it was worth
 * anything.
 *
 * Dated after 2026_08_03_100000 (which creates product_warranties, altered by
 * group 2) and after 2026_08_03_110100 (which last widened the payments tender
 * enum, widened again at the end here).
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- 1. The IMEI register --------------------------------------------
        Schema::create('mobile_handsets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            /**
             * Nullable because a handset is booked in at goods receipt, before it
             * has an owner — that is the whole point, so the counter can scan
             * rather than type at the point of sale. nullOnDelete so removing a
             * customer does not erase the handset's service history.
             */
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            /**
             * Brand and model denormalised off the product on purpose: a shop
             * takes in handsets it never sold — most of the repair trade — and
             * those have no product row to read a name from.
             */
            $table->string('brand', 60)->nullable();
            $table->string('model_name', 120)->nullable();
            $table->string('storage', 20)->nullable();
            $table->string('colour', 40)->nullable();

            /**
             * The identifiers. `imei_1` is the one the trade lives by, but it is
             * still nullable: a feature phone with a rubbed-off label and a
             * tablet with no cellular radio both turn up, and a register that
             * refused them would simply stop being used. MySQL permits repeated
             * NULLs in a unique index, so those cases fit the rules below.
             *
             * Two IMEIs because a dual-SIM handset stamps both, and the customer
             * reads out whichever one they found.
             */
            $table->string('imei_1', 20)->nullable();
            $table->string('imei_2', 20)->nullable();
            $table->string('serial_no', 100)->nullable();

            /**
             * in_stock → sold is the ordinary path. `returned` and `scrapped` are
             * terminal and exist so a handset that came back or died is not
             * silently deleted — its warranty cards, policies and job cards still
             * refer to it, and a deleted row makes all of them unreadable.
             */
            $table->enum('status', ['in_stock', 'sold', 'returned', 'scrapped'])->default('in_stock');
            // Set when the shop did not sell it: a phone brought in for repair, or
            // insured over the counter having been bought elsewhere.
            $table->boolean('is_third_party')->default(false);

            $table->foreignId('sale_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->date('sold_at')->nullable();

            /**
             * The standard cover this model carries, so issuing a warranty card at
             * the counter fills its own end date instead of asking a salesman to
             * count twelve months forward on a calendar. Copied from the product's
             * policy at registration, not referenced — shortening next season's
             * terms must not retroactively cancel cover a customer already holds.
             */
            $table->unsignedSmallInteger('warranty_months')->nullable();

            $table->timestamp('last_service_at')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            /**
             * One row per identifier, per tenant. Duplicates are what ruin an
             * aftersales register: the second repair goes on the second row, and
             * the "still under warranty?" the counter reads out comes off a record
             * with no history on it.
             *
             * An IMEI is globally unique by construction, so a clash is always a
             * mis-key — usually the same box booked in twice, which is exactly what
             * this index is here to catch.
             */
            $table->unique(['tenant_id', 'imei_1'], 'mobile_handsets_imei_unique');
            $table->unique(['tenant_id', 'serial_no'], 'mobile_handsets_serial_unique');
            $table->index(['tenant_id', 'imei_2'], 'mobile_handsets_imei2_idx');
            $table->index(['tenant_id', 'customer_id'], 'mobile_handsets_customer_idx');
            $table->index(['tenant_id', 'status'], 'mobile_handsets_status_idx');
        });

        // --- 2. A handset on the core warranty card --------------------------
        Schema::table('product_warranties', function (Blueprint $table) {
            /**
             * Nullable because the same register still serves every other retail
             * trade — a pair of shoes has no IMEI — and nullOnDelete because
             * scrapping a handset must not delete the cover that was given on it.
             */
            $table->foreignId('handset_id')->nullable()->after('variant_id')
                ->constrained('mobile_handsets')->nullOnDelete();

            $table->index(['tenant_id', 'handset_id'], 'product_warranties_handset_idx');
        });

        // --- 3. The insurance book ------------------------------------------
        Schema::create('mobile_insurance_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('policy_number', 40);

            /**
             * The underwriter, and their own number for the policy. Both matter
             * because the shop is not the one paying out: when the customer rings
             * about a cracked screen they are sent to this company quoting this
             * number, and "some insurance we sold you" is not an answer a counter
             * can give.
             */
            $table->string('provider', 120);
            $table->string('provider_policy_no', 60)->nullable();

            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('handset_id')->nullable()->constrained('mobile_handsets')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            /**
             * The IMEI as text as well as the link. A policy is very often sold on
             * a handset this shop never sold — that is the walk-in insurance
             * business — and then the number off the box is the only handle there
             * is until someone registers the unit.
             */
            $table->string('imei', 20)->nullable();
            // What it was sold on, so the premium traces back to the money.
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('coverage_months')->nullable();

            // The premium the customer paid, what the policy pays out, and what
            // they still have to pay per claim. All three are argued about at
            // claim time, and the excess is the one nobody remembers being told.
            $table->decimal('premium', 15, 2)->default(0);
            $table->decimal('sum_insured', 15, 2)->nullable();
            $table->decimal('deductible', 15, 2)->default(0);

            /**
             * The perils, as three flags rather than a JSON blob. These are the
             * exact three things a counter is asked about by name — "does it cover
             * if the screen cracks / if it falls in water / if it is stolen?" — and
             * a flag can be filtered on when the shop wants to know how many live
             * theft policies it has written.
             */
            $table->boolean('covers_accidental_damage')->default(true);
            $table->boolean('covers_liquid_damage')->default(false);
            $table->boolean('covers_theft')->default(false);

            /**
             * How many claims the term allows, defaulting to the one that almost
             * every screen-protection policy in this trade allows. This is the
             * limb a warranty card does not have and the reason insurance could
             * not simply be a fourth `source` on one: cover here is *used up*, and
             * a register that did not know it would send a customer away certain
             * of a second replacement nobody is going to fund.
             */
            $table->unsignedTinyInteger('claim_limit')->default(1);

            $table->enum('status', ['active', 'expired', 'claimed', 'void'])->default('active');
            /**
             * Claims are counted, not listed. A claim *is* a repair job card
             * (service_jobs.insurance_policy_id) — the shop replaces the screen and
             * bills the insurer, it does not open a second document — so a claims
             * table would be an emptier copy of work that already exists.
             */
            $table->unsignedInteger('claims_count')->default(0);
            $table->timestamp('last_claim_at')->nullable();

            $table->text('terms')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'policy_number'], 'mobile_policies_number_unique');
            // The counter lookup: somebody reads fifteen digits off the back of a
            // handset and expects an answer before they have finished.
            $table->index(['tenant_id', 'imei'], 'mobile_policies_imei_idx');
            $table->index(['tenant_id', 'handset_id'], 'mobile_policies_handset_idx');
            $table->index(['tenant_id', 'status', 'end_date'], 'mobile_policies_expiry_idx');
        });

        // --- 4. The finance file --------------------------------------------
        Schema::create('mobile_emi_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('plan_number', 40);
            // An EMI file always has a borrower — the one party here that cannot
            // be a walk-in, because somebody owes the instalments.
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            /**
             * The distinction the whole table turns on.
             *
             *  - finance_company  Bajaj, an HDFC EMI card, a bank. The financier
             *                     settles with the shop at disbursal; the customer
             *                     owes *them*. The schedule is then a service the
             *                     shop offers ("how many left, bhai?") and never a
             *                     receivable.
             *  - in_house         the shop financed it. Every pending instalment is
             *                     money the shop is still owed, so collecting one
             *                     writes a payment against the invoice.
             *
             * Storing this rather than inferring it is what stops the two being
             * confused — which would either invent revenue the shop never received
             * or hide receivables it is carrying.
             */
            $table->enum('financier', ['in_house', 'finance_company'])->default('in_house');
            $table->string('financier_name', 120)->nullable();
            $table->string('loan_account_no', 60)->nullable();

            // What was financed. The invoice is the money; the handset is what the
            // customer is paying off, which is what they quote on the phone.
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('handset_id')->nullable()->constrained('mobile_handsets')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('imei', 20)->nullable();

            $table->decimal('asset_value', 15, 2)->default(0);
            $table->decimal('down_payment', 15, 2)->default(0);
            // asset_value − down_payment, stored rather than derived because a
            // subvention or a rounded-off file makes the two disagree, and it is
            // the financed figure the schedule is built from.
            $table->decimal('financed_amount', 15, 2)->default(0);
            $table->decimal('processing_fee', 15, 2)->default(0);
            $table->decimal('interest_percent', 6, 3)->default(0);

            $table->unsignedSmallInteger('tenure_months');
            $table->decimal('instalment_amount', 15, 2)->default(0);
            $table->date('first_due_date');

            $table->enum('status', ['active', 'closed', 'defaulted', 'cancelled'])->default('active');
            $table->date('closed_on')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'plan_number'], 'mobile_emi_number_unique');
            $table->index(['tenant_id', 'customer_id'], 'mobile_emi_customer_idx');
            $table->index(['tenant_id', 'status'], 'mobile_emi_status_idx');
            $table->index(['tenant_id', 'imei'], 'mobile_emi_imei_idx');
        });

        Schema::create('mobile_emi_instalments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('emi_plan_id')->constrained('mobile_emi_plans')->cascadeOnDelete();

            $table->unsignedSmallInteger('sequence');
            $table->date('due_date');
            $table->decimal('amount', 15, 2);

            /**
             * Three states and not four. "Overdue" is not stored: it is `pending`
             * with a due date in the past, and the moment it becomes true nobody is
             * around to write it down. A stored flag needs a nightly sweep to stay
             * true, and a register that is wrong between midnight and whenever the
             * sweep ran is worse than one that computes the answer when asked.
             */
            $table->enum('status', ['pending', 'paid', 'waived'])->default('pending');
            $table->date('paid_on')->nullable();
            $table->decimal('paid_amount', 15, 2)->default(0);
            /**
             * The receipt this collection wrote, for in-house files only. Null on a
             * finance-company file because the money came from the financier at
             * disbursal and was banked then — writing a payment now would bank the
             * same money twice.
             */
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();

            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique(['emi_plan_id', 'sequence'], 'mobile_emi_seq_unique');
            // The collections list: what falls due this week, oldest first.
            $table->index(['tenant_id', 'status', 'due_date'], 'mobile_emi_due_idx');
        });

        // --- 5. The part-exchange register ----------------------------------
        Schema::create('mobile_buybacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('buyback_number', 40);
            // Nullable: a shop quotes a valuation to whoever walks in, and most
            // quotes never become a customer record.
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            // The old handset as free text. It is somebody else's phone and very
            // often a model this shop has never stocked, so it does not belong in
            // the catalogue until the shop decides to resell it.
            $table->string('brand', 60);
            $table->string('model_name', 80);
            $table->string('imei', 20)->nullable();
            $table->string('device_serial', 60)->nullable();
            $table->string('storage', 20)->nullable();
            $table->string('colour', 40)->nullable();
            $table->unsignedSmallInteger('age_months')->nullable();

            /**
             * The grade *is* the valuation. A/B/C/D is how this trade prices a used
             * handset — everything else (screen marks, battery health, whether it
             * powers on at all) is evidence for the grade, and the grade is what
             * the buy-price list is written against.
             */
            $table->enum('grade', ['A', 'B', 'C', 'D'])->default('B');
            $table->text('defects')->nullable();
            // What came with it. These move the price, and they are also what an
            // argument at handover is about.
            $table->boolean('has_box')->default(false);
            $table->boolean('has_charger')->default(false);
            $table->boolean('has_bill')->default(false);

            // base − deduction + bonus = offered. All four kept because the
            // customer is shown the arithmetic, and because a brand's exchange
            // bonus is reimbursed by the brand and has to stay identifiable.
            $table->decimal('base_value', 15, 2)->default(0);
            $table->decimal('deduction', 15, 2)->default(0);
            $table->decimal('bonus_value', 15, 2)->default(0);
            $table->decimal('offered_value', 15, 2)->default(0);

            /**
             * quoted → accepted → settled, with rejected and expired available from
             * the quote.
             *
             * `accepted` is the interesting state: the shop is holding the handset
             * and owes the customer its value. That is a credit, exactly like an
             * unsettled old-gold receipt, and it is why settlement is a separate
             * act — a customer commonly sells today and buys on the next visit.
             */
            $table->enum('status', ['quoted', 'accepted', 'settled', 'rejected', 'expired'])
                ->default('quoted');
            $table->date('quoted_on');
            // Used-handset prices move weekly, so a quote has a shelf life.
            $table->date('valid_until')->nullable();
            $table->date('accepted_on')->nullable();

            // Settlement against the new bill. The payment is a tender of mode
            // 'buyback', so the sale bills at the price on the shelf and the
            // trade-in shows as what it is rather than as a discount.
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->date('settled_on')->nullable();

            /**
             * What became of the handset. Taking it into stock is an explicit,
             * separate act because it is genuinely a decision: a grade A trade-in
             * goes on the refurbished shelf and a grade D one is spares. Until
             * somebody makes that call the handset is in a drawer, which is exactly
             * what these columns being null means.
             */
            $table->foreignId('stock_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('stock_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->timestamp('taken_into_stock_at')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'buyback_number'], 'mobile_buybacks_number_unique');
            $table->index(['tenant_id', 'status'], 'mobile_buybacks_status_idx');
            $table->index(['tenant_id', 'customer_id'], 'mobile_buybacks_customer_idx');
            $table->index(['tenant_id', 'imei'], 'mobile_buybacks_imei_idx');
        });

        // --- 6. Repair-job-card columns on the service job ------------------
        Schema::table('service_jobs', function (Blueprint $table) {
            /**
             * Both anchored after `customer_id`, which is a core column. Anchoring
             * after another plugin's columns would couple this migration to whether
             * that vertical is registered at all.
             *
             * `item_serial` already carries a typed-in IMEI for a handset with no
             * register row, so this column adds the link and not a second copy of
             * the number.
             */
            $table->foreignId('handset_id')->nullable()->after('customer_id')
                ->constrained('mobile_handsets')->nullOnDelete();
            /**
             * The policy being claimed against. Distinct from the existing
             * `is_warranty` flag, which only says the customer is not paying: this
             * says *under what*, which is what an insurer asks for and what nobody
             * can reconstruct six months later.
             */
            $table->foreignId('insurance_policy_id')->nullable()->after('customer_id')
                ->constrained('mobile_insurance_policies')->nullOnDelete();
        });

        // --- 7. Part-exchange as a tender -----------------------------------
        if ($this->isMysql()) {
            /**
             * A trade-in settles part of a bill the same way old gold, a gift
             * voucher, redeemed points or an exchange credit do — as a tender. Its
             * own mode rather than reusing 'exchange' so the cash book can say
             * which register the credit came out of, which is the same reason old
             * gold and vouchers each got one.
             */
            DB::statement(
                'ALTER TABLE payments MODIFY mode '
                ."ENUM('cash','card','upi','credit','loyalty','exchange','old_gold','voucher','buyback') "
                ."NOT NULL DEFAULT 'cash'",
            );
        }
    }

    public function down(): void
    {
        if ($this->isMysql()) {
            // Fold trade-in tenders back to the nearest surviving mode rather than
            // failing the rollback on rows the narrower enum cannot hold.
            DB::table('payments')->where('mode', 'buyback')->update(['mode' => 'exchange']);
            DB::statement(
                'ALTER TABLE payments MODIFY mode '
                ."ENUM('cash','card','upi','credit','loyalty','exchange','old_gold','voucher') "
                ."NOT NULL DEFAULT 'cash'",
            );
        }

        Schema::table('service_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('insurance_policy_id');
            $table->dropConstrainedForeignId('handset_id');
        });

        Schema::dropIfExists('mobile_buybacks');
        Schema::dropIfExists('mobile_emi_instalments');
        Schema::dropIfExists('mobile_emi_plans');
        Schema::dropIfExists('mobile_insurance_policies');

        Schema::table('product_warranties', function (Blueprint $table) {
            $table->dropIndex('product_warranties_handset_idx');
            $table->dropConstrainedForeignId('handset_id');
        });

        Schema::dropIfExists('mobile_handsets');
    }

    private function isMysql(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
