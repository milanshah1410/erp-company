<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stationery & School Supplies plugin schema: the institutions a shop supplies,
 * the class booklist each one publishes, and the columns that let the school's
 * own order be an ordinary sales order.
 *
 * The organising fact of this trade is that it has two completely different
 * customers and one of them is not a person. Half the year the shop sells pens
 * over a counter; for about ten weeks it supplies *schools* — and a school does
 * not buy things, it publishes a list and sends four hundred parents to fetch it.
 * Nothing in a general ERP can express "Class 5 at Sunrise School, session
 * 2026-27, these twenty-two items", which is why the parent's bill takes eleven
 * minutes to type in every other system and why the shop keeps the real answer in
 * a photocopied sheet under the counter.
 *
 * Three groups of change, and deliberately no more:
 *
 *  1. stationery_institutions — the school as a *catalogue owner*, which is not
 *     the same thing as the customer it bills to. The trust that pays the bill
 *     and the school that publishes the list are routinely different names, and
 *     the parents buying against the list are not the school's ledger at all.
 *     So the institution carries the season (board, medium, academic year, the
 *     concession negotiated for parents) and points at a customer for the money.
 *
 *  2. stationery_class_kits + _items — the booklist itself, per class per
 *     session. Deliberately not a `bom`: a BOM is consumed to *produce* a
 *     manufactured good and is driven by a manufacturing order, while a kit is a
 *     sales list that explodes into invoice lines and produces nothing. They also
 *     fail differently — a kit routinely has three items out of stock in June and
 *     still has to sell, which a BOM has no way to say.
 *
 *  3. Columns on sales_orders and sales_quotations. What a school's bulk order
 *     *is* is an ordinary sales order — same stock, same GST, same receivable,
 *     same partial delivery — placed by an institution, against a booklist,
 *     under a purchase-order number the school's accounts office will not pay
 *     without. Four nullable columns say that; a parallel "school order"
 *     document would have to re-implement pricing, dispatch and invoicing before
 *     it was worth anything.
 *
 * Notably absent: anything to do with bulk pricing. Quantity slabs already exist
 * (`price_lists.min_quantity`, resolved by `Customer::priceFor`), and a second
 * slab table would be a worse copy that the POS did not consult.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- 1. The institutions the shop supplies ---------------------------
        Schema::create('stationery_institutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            /**
             * Generated, never typed. It goes on the top of a booklist, a
             * quotation and a delivery challan, and it is what a shop says on the
             * phone to distinguish the two schools in town with the same saint's
             * name.
             */
            $table->string('code', 40);
            /**
             * The school's own name — which is not the customer's. "Sunrise
             * English School" is what is printed on the list; "Shree Sunrise
             * Kelavani Mandal Trust" is who the invoice is raised to. Storing one
             * and hoping is how the wrong name ends up on four hundred bills.
             */
            $table->string('name', 160);
            /**
             * The ledger party the institution's *own* purchases are billed to.
             * Nullable because a shop very often supplies a school it has never
             * invoiced directly: every rupee comes from parents at the counter,
             * and the school is a list publisher rather than a debtor.
             */
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            $table->enum('type', ['school', 'college', 'coaching', 'office', 'government', 'ngo', 'other'])
                ->default('school');
            // CBSE / GSEB / ICSE / IB / State. Free text rather than an enum:
            // boards are a moving list and a shop near a state border deals with
            // three of them plus one nobody has heard of.
            $table->string('board', 40)->nullable();
            $table->string('medium', 30)->nullable();

            /**
             * The session being supplied, as the trade writes it: "2026-27". The
             * same school is a different job every year — new list, new classes,
             * new rates — and every kit, quotation and order here is read in the
             * context of one session.
             */
            $table->string('academic_year', 12);
            // When the season opens here. Two schools in one town start three
            // weeks apart, and that is the whole difference between a planned
            // purchase and a panic one.
            $table->date('session_starts_on')->nullable();
            // Roughly how big this school is. Not a count of anything the shop
            // owns — it is what turns a booklist into a purchase quantity.
            $table->unsignedInteger('student_count')->nullable();

            $table->string('contact_person', 100)->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->string('contact_email', 120)->nullable();
            $table->text('address')->nullable();

            /**
             * The shop is this school's appointed supplier for the session. It is
             * a fact worth storing rather than inferring, because it changes what
             * the shop dares to buy: an authorised vendor stocks the whole list
             * deep in April, and an unauthorised one is guessing.
             */
            $table->boolean('is_authorised_vendor')->default(false);
            /**
             * Two discounts, because there are two buyers. `parent_discount_percent`
             * is the concession the school negotiated on its list — every parent
             * gets it, and the shop cannot quietly not give it. `school_discount_percent`
             * is what the institution itself buys at, which is a different and
             * usually larger number.
             */
            $table->decimal('parent_discount_percent', 5, 2)->default(0);
            $table->decimal('school_discount_percent', 5, 2)->default(0);

            $table->unsignedSmallInteger('credit_days')->default(0);
            /**
             * The school will not pay a bill that does not quote its own purchase
             * order. This is the single commonest reason an institutional
             * receivable ages past ninety days, and it is knowable in advance —
             * so it is a flag on the account rather than a lesson learned in
             * September.
             */
            $table->boolean('requires_po')->default(false);

            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'stationery_institutions_code_unique');
            $table->index(['tenant_id', 'is_active'], 'stationery_institutions_active_idx');
            $table->index(['tenant_id', 'academic_year'], 'stationery_institutions_year_idx');
        });

        // --- 2. The booklist -------------------------------------------------
        Schema::create('stationery_class_kits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kit_number', 40);
            /**
             * Nullable on purpose: a shop keeps its own generic lists too ("Class
             * 5 — standard set") for the walk-in whose school publishes nothing,
             * and those are real sellable kits, not drafts of a school's.
             * cascadeOnDelete because a booklist without the school that
             * published it is not a booklist.
             */
            $table->foreignId('institution_id')->nullable()->constrained('stationery_institutions')->cascadeOnDelete();

            $table->string('academic_year', 12);
            // "Class 5", "Nursery", "FYBCom" — a label, not a number. Indian
            // schools count in at least four different vocabularies and the list
            // has to be findable by the words printed at the top of it.
            $table->string('class_label', 40);
            // Science / Commerce / Arts. Empty below Class 11 and load-bearing
            // above it, where one class is three completely different lists.
            $table->string('stream', 40)->nullable();
            $table->string('title', 140)->nullable();

            /**
             * draft → published → archived. Publishing is what makes a kit
             * sellable, and it matters because a half-typed booklist looks
             * exactly like a finished one: a counter that can sell a draft will
             * sell the eleven items somebody had entered before lunch and send
             * the parent home short.
             */
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->timestamp('published_at')->nullable();

            /**
             * The set discount, applied when the whole kit is bought together.
             * Distinct from the institution's parent concession, which applies
             * however the parent buys — this one is the shop's own reason to make
             * them take the lot.
             */
            $table->decimal('discount_percent', 5, 2)->default(0);
            /**
             * The school insists on the whole list. Some do and some let parents
             * pick, and the difference decides whether the counter is allowed to
             * drop the ₹40 pencil box a parent already owns.
             */
            $table->boolean('is_mandatory_set')->default(false);
            // What the shop expects to sell of this one — the number every
            // April purchase order is worked back from.
            $table->unsignedInteger('expected_students')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'kit_number'], 'stationery_kits_number_unique');
            /**
             * An index and not a unique key. The natural key is
             * (institution, year, class, stream) but two of those are nullable,
             * and MySQL lets repeated NULLs through a unique index — so a shop's
             * generic kits would be uniqued and a school's would not, which is
             * worse than neither. The duplicate check lives in the request, where
             * it can say something useful.
             */
            $table->index(['tenant_id', 'institution_id', 'academic_year'], 'stationery_kits_season_idx');
            $table->index(['tenant_id', 'status'], 'stationery_kits_status_idx');
        });

        Schema::create('stationery_class_kit_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kit_id')->constrained('stationery_class_kits')->cascadeOnDelete();

            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->decimal('quantity', 10, 3)->default(1);

            /**
             * Some lines are suggestions. A school's list routinely ends with
             * "optional: geometry box, dictionary", and a counter that cannot
             * tell the difference either oversells every parent or under-supplies
             * the ones who wanted them.
             */
            $table->boolean('is_optional')->default(false);
            /**
             * The school's own wording, kept verbatim — "Ruled long book, 172
             * pages" — because the parent is holding the sheet it was copied
             * from and will compare. The product name is what the shop calls it;
             * this is what the school called it.
             */
            $table->string('remark', 160)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'kit_id'], 'stationery_kit_items_kit_idx');
            $table->index(['tenant_id', 'product_id'], 'stationery_kit_items_product_idx');
        });

        // --- 3. The school's own order, on the ordinary sales spine ----------
        if ($this->isMysql()) {
            /**
             * A school buying five hundred diaries is a sale. Widening the
             * channel rather than inventing a document means the order shows up
             * in GST returns, receivables, the customer ledger and every sales
             * report already written — and lets the season be reported on by
             * filtering one column.
             */
            $this->setSalesChannels([...$this->salesChannels(), 'institution']);
        }

        Schema::table('sales_orders', function (Blueprint $table) {
            /**
             * Prefixed `stn_` for the reason every plugin column on a shared core
             * table is: `institution_id` on `sales_orders` would read as
             * something core owns, and a bare `po_number` is a promise this
             * plugin cannot keep — a customer purchase-order reference is
             * generic, belongs in core, and would collide the day it is added
             * there. Nullable and meaningless to every other trade.
             */
            $table->foreignId('stn_institution_id')->nullable()->after('customer_id')
                ->constrained('stationery_institutions')->nullOnDelete();
            // The booklist this order was raised from, when it was. Kept so the
            // shop can answer "did we ever supply Class 5's list to them?" without
            // reading twenty-two line items.
            $table->foreignId('stn_class_kit_id')->nullable()->after('stn_institution_id')
                ->constrained('stationery_class_kits')->nullOnDelete();
            // The school's own purchase order. Not decoration: quoting it is a
            // precondition of being paid, which is why the institution carries a
            // `requires_po` flag and this is validated against it.
            $table->string('stn_po_number', 60)->nullable()->after('stn_class_kit_id');
            $table->date('stn_po_date')->nullable()->after('stn_po_number');

            $table->index(['tenant_id', 'stn_institution_id'], 'sales_orders_stn_institution_idx');
        });

        Schema::table('sales_quotations', function (Blueprint $table) {
            /**
             * A school order almost always starts as a quotation against a
             * tender, weeks before anyone orders anything. The same two
             * references ride on it so the enquiry is findable by school, and so
             * that converting it can carry them onto the order — see
             * SchoolQuotationObserver, which does that wherever the conversion
             * happens rather than only on this plugin's own screen.
             */
            $table->foreignId('stn_institution_id')->nullable()->after('customer_id')
                ->constrained('stationery_institutions')->nullOnDelete();
            $table->foreignId('stn_class_kit_id')->nullable()->after('stn_institution_id')
                ->constrained('stationery_class_kits')->nullOnDelete();

            $table->index(['tenant_id', 'stn_institution_id'], 'sales_quotations_stn_institution_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sales_quotations', function (Blueprint $table) {
            $table->dropIndex('sales_quotations_stn_institution_idx');
            $table->dropConstrainedForeignId('stn_class_kit_id');
            $table->dropConstrainedForeignId('stn_institution_id');
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropIndex('sales_orders_stn_institution_idx');
            $table->dropColumn(['stn_po_date', 'stn_po_number']);
            $table->dropConstrainedForeignId('stn_class_kit_id');
            $table->dropConstrainedForeignId('stn_institution_id');
        });

        if ($this->isMysql()) {
            // Orders raised on the widened channel have to land somewhere before
            // the value disappears, or the ALTER silently blanks them.
            DB::table('sales_orders')->where('channel', 'institution')->update(['channel' => 'standard']);
            $this->setSalesChannels(array_values(array_diff($this->salesChannels(), ['institution'])));
        }

        Schema::dropIfExists('stationery_class_kit_items');
        Schema::dropIfExists('stationery_class_kits');
        Schema::dropIfExists('stationery_institutions');
    }

    private function isMysql(): bool
    {
        return DB::getDriverName() === 'mysql';
    }

    /** @return list<string> */
    private function salesChannels(): array
    {
        $definition = DB::selectOne('SHOW COLUMNS FROM sales_orders WHERE Field = ?', ['channel']);
        preg_match_all("/'([^']+)'/", $definition->Type, $matches);

        return $matches[1] ?? [];
    }

    /** @param  list<string>  $channels */
    private function setSalesChannels(array $channels): void
    {
        $quoted = implode(',', array_map(
            static fn (string $v) => "'".addslashes($v)."'",
            array_values(array_unique($channels)),
        ));

        DB::statement("ALTER TABLE sales_orders MODIFY channel ENUM({$quoted}) NOT NULL DEFAULT 'standard'");
    }
};
