<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Electronics & Appliances plugin schema: the serial/IMEI register, the
 * service-request desk, and the columns that let existing core records — a
 * warranty card, a service job — be worked against a specific machine.
 *
 * The organising idea is the mirror of Automobile's. A garage serves vehicles; an
 * electronics shop serves *units*. Two customers buy the same 55" television and
 * only the serial number tells them apart afterwards — which one is still in
 * warranty, which one has been opened twice, which one the AMC was sold against.
 * Everything this trade argues about is settled against the unit, so the unit is
 * the master record and the customer hangs off it.
 *
 * **There is deliberately no warranty table here.** The core `warranty` module's
 * `product_warranties` is already the register a customer's claim is settled
 * against, and it was written for exactly these trades. A second one would mean
 * an appliance dealer holding two warranty screens that disagree. What this
 * plugin adds instead are the columns that register was missing for goods that
 * get installed rather than handed over — see group 2.
 *
 * Three groups of change:
 *
 *  1. electronics_units — one row per physical machine the shop has sold or is
 *     holding. Deliberately separate from the core `serial_numbers` table: that
 *     one is an inventory device (which serial sits in which warehouse, what
 *     document moved it) and is consumed by stock. This one is an *aftersales*
 *     record — who owns it, when it was installed, when it was last serviced,
 *     what cover it carries — and it outlives the stock movement that created it.
 *     Both can name the same serial; they answer different questions.
 *
 *  2. Unit and scope columns on product_warranties. `unit_id` gives a card a
 *     machine rather than only a typed-in serial, which is what makes "is this
 *     covered?" answerable from a scanner. The scope pair is the limb this trade
 *     needs and motor cover does not: "3 years" on a television almost always
 *     means parts *and* labour in year one and parts only afterwards, so a
 *     register that answers a flat yes/no hands out a free service call nobody
 *     promised. `is_onsite` decides whether the counter says "we'll send someone"
 *     or "bring it in" — a van and half a technician's day.
 *
 *  3. electronics_service_requests — the desk. An installation, a delivery, a
 *     demo, an AMC visit and a complaint all arrive the same way (someone rings)
 *     and most of them never become a job card: half are answered on the phone,
 *     some are cancelled, some are just a delivery to schedule. Opening a job
 *     card per call would leave the worksheet full of work nobody ever did.
 *     Converting is an explicit act and the two rows stay linked afterwards.
 *
 *  4. Unit columns on service_jobs. A repair *is* a service job — same
 *     lifecycle, same spare-part issue, same billing through the sales spine —
 *     with a unit, a request and possibly a warranty card behind it. Adding the
 *     columns beats a parallel table that would have to re-implement parts issue
 *     and invoicing before it was worth anything.
 *
 * Dated after 2026_08_03_100000, which creates product_warranties: group 2 alters
 * that table, so this migration cannot run before it exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- 1. The unit register -------------------------------------------
        Schema::create('electronics_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            /**
             * Nullable because a unit is often registered before it is sold —
             * a showroom books IMEIs in at goods receipt so the counter can scan
             * rather than type at the point of sale. nullOnDelete so removing a
             * customer does not erase the machine's service history.
             */
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            /**
             * Brand and model are denormalised off the product on purpose. A
             * shop takes in machines it never sold — that is most of the repair
             * trade — and those have no product row to read a name from.
             */
            $table->string('brand', 60)->nullable();
            $table->string('model_name', 120)->nullable();

            /**
             * Three identifiers, all nullable, because the trades under this
             * vertical stamp different things: a television has a serial, a
             * phone has one or two IMEIs and usually a serial too, and a ceiling
             * fan has nothing at all until the shop writes a sticker on it.
             * MySQL allows repeated NULLs in a unique index, so all three cases
             * fit under the uniqueness rules below.
             */
            $table->string('serial_no', 100)->nullable();
            $table->string('imei_1', 20)->nullable();
            $table->string('imei_2', 20)->nullable();

            /**
             * in_stock → sold is the ordinary path. `returned` and `scrapped`
             * are terminal and exist so a unit that came back or died is not
             * silently deleted — its warranty claims and job cards still refer
             * to it, and a deleted row makes them unreadable.
             */
            $table->enum('status', ['in_stock', 'sold', 'returned', 'scrapped'])->default('in_stock');
            // Set when the shop did not sell it — a machine brought in for repair.
            $table->boolean('is_third_party')->default(false);

            $table->foreignId('sale_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->date('sold_at')->nullable();
            $table->date('delivered_at')->nullable();

            /**
             * Installation is its own fact, not a synonym for delivery. A washing
             * machine is delivered on Tuesday and plumbed in on Thursday, and the
             * gap between those two dates is the single commonest complaint in
             * this trade — so it is stored, and the "awaiting installation" work
             * list is derived from it rather than from a status somebody has to
             * remember to move.
             */
            $table->boolean('installation_required')->default(false);
            $table->date('installed_at')->nullable();

            /**
             * The standard cover this model carries, so registering a warranty
             * at the counter fills its own end date instead of asking a salesman
             * to count twelve months forward on a calendar.
             */
            $table->unsignedSmallInteger('warranty_months')->nullable();
            // The AMC this unit is on, if one was sold. Core's contract table —
            // an AMC on a chiller is the same document as an AMC on anything else.
            $table->foreignId('amc_contract_id')->nullable()->constrained('service_contracts')->nullOnDelete();

            $table->timestamp('last_service_at')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            /**
             * One row per identifier, per tenant. Duplicates are what ruin an
             * aftersales register: the second repair goes on the second row, and
             * the "still under warranty?" the counter reads out is answered from
             * a record with no history on it.
             */
            $table->unique(['tenant_id', 'serial_no'], 'electronics_units_serial_unique');
            $table->unique(['tenant_id', 'imei_1'], 'electronics_units_imei_unique');
            $table->index(['tenant_id', 'customer_id'], 'electronics_units_customer_idx');
            $table->index(['tenant_id', 'status'], 'electronics_units_status_idx');
            // The fitters' work list: sold, needs installing, not yet installed.
            $table->index(['tenant_id', 'installation_required', 'installed_at'], 'electronics_units_install_idx');
        });

        // --- 2. Unit and scope columns on the core warranty card ---------------
        Schema::table('product_warranties', function (Blueprint $table) {
            /**
             * The machine the card was issued against.
             *
             * The core register already carries `serial_no`, and for a footwear
             * or watch counter that is the whole answer. It is not enough here: an
             * appliance is looked up by scanning it, the cover has to survive a
             * customer reading the number out wrong over the phone, and the same
             * machine accumulates a service history that a free-text field cannot
             * be joined to.
             *
             * Nullable and nullOnDelete, so a workspace without this plugin keeps
             * an unused column and a card outlives the unit row it named.
             */
            $table->foreignId('unit_id')->nullable()->after('variant_id')
                ->constrained('electronics_units')->nullOnDelete();

            /**
             * What the cover actually pays for — the limb this trade needs and
             * motor cover does not. "3 years warranty" on a television almost
             * always means parts and labour in year one and the panel only
             * afterwards, so a register that answers a flat yes/no gives away a
             * service call nobody promised.
             *
             * Both default true: that is what a fresh manufacturer cover is, and
             * it is also what every card written before this migration meant, so
             * back-filling the default changes no existing answer.
             */
            $table->boolean('covers_parts')->default(true)->after('coverage_months');
            $table->boolean('covers_labour')->default(true)->after('covers_parts');
            /**
             * Onsite or carry-in. Decides whether the counter says "we'll send
             * someone" or "bring it in" — a van and half a technician's day.
             * Defaults false because carry-in is the norm everywhere except the
             * trades that install what they sell.
             */
            $table->boolean('is_onsite')->default(false)->after('covers_labour');

            $table->index(['tenant_id', 'unit_id'], 'product_warranties_unit_idx');
        });

        // --- 3. The service-request desk --------------------------------------
        Schema::create('electronics_service_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('request_number', 40);

            /**
             * Everything the desk takes a call about. Kept as one document rather
             * than five because the intake is identical — who, what machine,
             * when, where — and only the outcome differs: a repair and an
             * installation both become job cards, a delivery and a demo usually
             * just get done, and a complaint is often answered on the phone.
             */
            $table->enum('type', ['installation', 'repair', 'demo', 'delivery', 'amc_visit'])
                ->default('repair');
            $table->enum('source', ['walk_in', 'phone', 'online', 'whatsapp', 'email', 'campaign'])
                ->default('phone');
            /**
             * logged → scheduled → assigned → converted, with closed and
             * cancelled available throughout. `converted` means a job card
             * exists; `closed` means it was finished without one, which is the
             * honest outcome for most deliveries and demos. Neither is reversible
             * from the desk.
             */
            $table->enum('status', ['logged', 'scheduled', 'assigned', 'converted', 'closed', 'cancelled'])
                ->default('logged');
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');

            $table->foreignId('unit_id')->nullable()->constrained('electronics_units')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            // The thing, when there is no unit row for it — a caller who has not
            // found the serial number yet, which is most callers.
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('promised_at')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();

            /**
             * Onsite by default: an installation is at the customer's home by
             * definition, and so is most appliance work. The address is on the
             * request rather than read off the customer because the machine is
             * frequently not at the billing address — a gift, a second property,
             * an office.
             */
            $table->boolean('is_onsite')->default(true);
            $table->text('address')->nullable();
            $table->string('contact_phone', 20)->nullable();

            $table->text('complaint')->nullable();
            $table->decimal('estimated_cost', 15, 2)->default(0);

            /**
             * The cover found when the call was taken, and the answer given. Both
             * stored: `under_warranty` is what the desk *told the customer*, and
             * it has to survive the cover later expiring — otherwise a promise
             * made in good faith on Monday reads as a mistake on Friday.
             */
            $table->boolean('under_warranty')->default(false);
            $table->foreignId('warranty_id')->nullable()->constrained('product_warranties')->nullOnDelete();

            // Set the moment the request becomes work. Both directions are
            // stored (service_jobs.service_request_id points back) so either
            // screen reaches the other without a join through a third table.
            $table->foreignId('service_job_id')->nullable()->constrained('service_jobs')->nullOnDelete();
            $table->timestamp('reminder_sent_at')->nullable();

            $table->timestamp('closed_at')->nullable();
            $table->string('closure_note', 500)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'request_number'], 'electronics_requests_number_unique');
            // The day view: "who are we visiting tomorrow", in time order.
            $table->index(['tenant_id', 'scheduled_at'], 'electronics_requests_day_idx');
            $table->index(['tenant_id', 'status'], 'electronics_requests_status_idx');
            $table->index(['tenant_id', 'unit_id'], 'electronics_requests_unit_idx');
        });

        // --- 4. Unit columns on the service job -------------------------------
        Schema::table('service_jobs', function (Blueprint $table) {
            /**
             * All three anchored after `customer_id`, which is a core column.
             * Anchoring after the Automobile plugin's columns would couple this
             * migration to whether that vertical is registered at all.
             */
            $table->foreignId('device_unit_id')->nullable()->after('customer_id')
                ->constrained('electronics_units')->nullOnDelete();
            $table->foreignId('service_request_id')->nullable()->after('customer_id')
                ->constrained('electronics_service_requests')->nullOnDelete();
            /**
             * The warranty card being claimed against — core's, not a copy.
             * Distinct from the existing `is_warranty` flag, which only says the
             * customer is not paying: this says *which* promise is being
             * honoured, and is what makes a claim auditable when the
             * manufacturer asks months later.
             *
             * A separate column from the Automobile plugin's `warranty_id`
             * because that one points at `automobile_warranties`. One column
             * cannot carry two foreign keys, and a shop that repairs both cars
             * and car stereos would have both plugins registered.
             */
            $table->foreignId('device_warranty_id')->nullable()->after('customer_id')
                ->constrained('product_warranties')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_warranty_id');
            $table->dropConstrainedForeignId('service_request_id');
            $table->dropConstrainedForeignId('device_unit_id');
        });

        Schema::dropIfExists('electronics_service_requests');

        Schema::table('product_warranties', function (Blueprint $table) {
            $table->dropIndex('product_warranties_unit_idx');
            $table->dropConstrainedForeignId('unit_id');
            $table->dropColumn(['covers_parts', 'covers_labour', 'is_onsite']);
        });

        Schema::dropIfExists('electronics_units');
    }
};
