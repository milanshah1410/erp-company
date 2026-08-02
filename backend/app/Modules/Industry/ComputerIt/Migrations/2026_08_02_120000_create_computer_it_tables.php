<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Computer & IT plugin schema: the installed-machine register, the cover on it,
 * the licence keys that go with it, and the call book a support desk works.
 *
 * The organising idea is the mirror of the workshop's. A garage serves vehicles;
 * a computer shop serves *machines it has already sold* — and goes on serving
 * them for years, at the customer's desk rather than over its own counter. Every
 * argument in this trade ("is this still covered?", "whose licence is this?",
 * "when was that machine last touched?") is settled against the machine, not
 * against whoever happens to be sitting at it. So the machine is the master
 * record and the customer hangs off it.
 *
 * Five groups of change:
 *
 *  1. computer_it_assets — the install base. Separate from the existing `assets`
 *     table on purpose: that one is the company's *own* fixed-asset register
 *     (what depreciation and internal maintenance are booked against), and
 *     merging the two would put a customer's laptop on the shop's balance sheet.
 *     Separate from core `serial_numbers` too: that is an inventory device —
 *     which serial is in which warehouse — and it is consumed by the stock
 *     movement, while this record outlives it by years.
 *
 *  2. computer_it_warranties — what is still covered, and until when. Unlike a
 *     vehicle's, IT cover runs on one clock (there is no odometer on a laptop)
 *     but it carries a *service level*: carry-in, on-site, next-business-day
 *     on-site. That is the limb that costs money here, because it decides
 *     whether an engineer travels, and a register storing only dates will
 *     promise a site visit the shop never sold.
 *
 *  3. computer_it_licence_keys — the software half of the sale. A key is not
 *     stock: it is a string worth money exactly once, with a seat count, either
 *     perpetual or running out. Selling the same key twice is the failure mode
 *     this table exists to prevent, which is why the key is unique per tenant
 *     rather than merely stored.
 *
 *  4. computer_it_service_calls — the desk. A call is not a job card: most are
 *     answered on the phone or fixed remotely and never become bench work, and a
 *     shop opening a job card per complaint would have a worksheet full of
 *     machines that never arrived. Converting is an explicit act.
 *
 *  5. Job-card columns on service_jobs. What a call becomes *is* an ordinary
 *     service job — same lifecycle, same spare-part issue, same billing through
 *     the sales spine — with a machine, a call and possibly a cover behind it.
 *     Adding three nullable columns is far more honest than a parallel table
 *     that would have to re-implement parts issue and invoicing to be useful.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- 1. The installed-machine register -------------------------------
        Schema::create('computer_it_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            /**
             * The sticker on the chassis. Generated rather than typed, because
             * it is what an engineer reads back down a phone line and what the
             * label printer puts on the machine.
             */
            $table->string('asset_tag', 40);
            /**
             * Nullable because a machine can be on the shelf before it has an
             * owner — a shop registers a serial at goods-in and allocates it at
             * delivery. nullOnDelete so removing a customer does not erase the
             * machine's service history.
             */
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            // What it is in the catalogue, when the shop sold it. Null for a
            // machine the shop only supports and never sold.
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            $table->enum('category', [
                'laptop', 'desktop', 'server', 'printer', 'network',
                'peripheral', 'ups', 'software', 'other',
            ])->default('laptop');
            $table->string('make', 60)->nullable();
            $table->string('model', 60)->nullable();
            /**
             * The serial is the machine's real identity — a warranty claim, an
             * RMA and the manufacturer's own portal are all keyed on it.
             * Nullable because an assembled desktop genuinely has none, and
             * unique per tenant because two rows for one machine is what splits
             * a service history in half. (MySQL allows repeated NULLs in a
             * unique index, so the assembled case still fits.)
             */
            $table->string('serial_no', 100)->nullable();
            // "i5-1235U · 16 GB · 512 GB NVMe" — free text on purpose: the spec
            // of a five-year-old machine is a sentence, not a schema.
            $table->text('specification')->nullable();
            $table->string('os', 60)->nullable();
            $table->string('mac_address', 40)->nullable();

            // Where the machine physically is, and who sits at it. This is the
            // whole point of an install base: an engineer sent to "Bodakdev, 2nd
            // floor, accounts" finds it; one sent to a company name does not.
            $table->string('site', 120)->nullable();
            $table->string('department', 80)->nullable();
            $table->string('assigned_user', 120)->nullable();

            $table->date('purchase_date')->nullable();
            $table->date('installed_on')->nullable();
            $table->foreignId('sale_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            /**
             * The AMC the machine is on, pointing at the core service module's
             * contract. The plugin ships no AMC table of its own — an annual
             * contract with a visit quota already exists and already suppresses
             * billing on the jobs booked under it.
             */
            $table->foreignId('amc_contract_id')->nullable()->constrained('service_contracts')->nullOnDelete();

            /**
             * in_stock → deployed is delivery; deployed ↔ in_service is the
             * machine being taken away and brought back; standby is the loaner
             * pool, which every shop of this kind keeps and nobody models.
             */
            $table->enum('status', ['in_stock', 'deployed', 'in_service', 'standby', 'retired'])
                ->default('deployed');

            // Preventive maintenance: an AMC usually promises N visits a year,
            // so the machine carries its own clock the way a vehicle does.
            $table->unsignedSmallInteger('service_interval_days')->nullable();
            $table->timestamp('last_service_at')->nullable();
            $table->date('next_service_due_at')->nullable();

            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'asset_tag'], 'computer_it_assets_tag_unique');
            $table->unique(['tenant_id', 'serial_no'], 'computer_it_assets_serial_unique');
            $table->index(['tenant_id', 'customer_id'], 'computer_it_assets_customer_idx');
            $table->index(['tenant_id', 'status'], 'computer_it_assets_status_idx');
            $table->index(['tenant_id', 'next_service_due_at'], 'computer_it_assets_due_idx');
        });

        // --- 2. The warranty register ----------------------------------------
        Schema::create('computer_it_warranties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('warranty_number', 40);
            /**
             * Four things get warranted here and they share every field that
             * matters: the machine (manufacturer cover), a part fitted to it (a
             * replacement SSD), the shop's own repair, and cover the customer
             * bought on top once the manufacturer's ran out.
             */
            $table->enum('type', ['product', 'part', 'service', 'extended'])->default('product');
            $table->enum('provider', ['manufacturer', 'dealer', 'third_party'])->default('manufacturer');

            $table->foreignId('asset_id')->nullable()->constrained('computer_it_assets')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            // The part, for a part warranty, plus whatever serial is on it.
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('serial_no', 100)->nullable();
            // What it was sold on, so a claim traces back to the money.
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('service_job_id')->nullable()->constrained('service_jobs')->nullOnDelete();

            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('coverage_months')->nullable();
            /**
             * The limb that costs money in this trade. "Three years" means
             * nothing on its own — three years of carry-in is the customer
             * driving over with the machine; three years of next-business-day
             * on-site is an engineer at their desk within a day, and it is sold
             * at a different price. Storing only dates promises the expensive
             * one for the cheap one's money.
             */
            $table->enum('service_level', ['carry_in', 'onsite', 'nbd_onsite'])->default('carry_in');

            $table->enum('status', ['active', 'expired', 'claimed', 'void'])->default('active');
            /**
             * Claims are counted, not listed: a claim *is* a job card worked
             * under this cover (service_jobs.it_warranty_id), so a claims table
             * would be an emptier copy of work that already exists. These two
             * columns let the register be read without touching the job book.
             */
            $table->unsignedInteger('claims_count')->default(0);
            $table->timestamp('last_claim_at')->nullable();

            $table->text('terms')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'warranty_number'], 'computer_it_warranties_number_unique');
            $table->index(['tenant_id', 'asset_id'], 'computer_it_warranties_asset_idx');
            $table->index(['tenant_id', 'serial_no'], 'computer_it_warranties_serial_idx');
            $table->index(['tenant_id', 'status', 'end_date'], 'computer_it_warranties_expiry_idx');
        });

        // --- 3. Licence keys --------------------------------------------------
        Schema::create('computer_it_licence_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            /**
             * The catalogue product, when the shop stocks the licence as a SKU —
             * and the printed name regardless, because a shop routinely supplies
             * keys for software it has never carried as stock.
             */
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('software', 120);
            $table->string('version', 40)->nullable();
            $table->string('publisher', 80)->nullable();
            /**
             * OEM dies with the machine, retail moves with the customer, volume
             * covers many seats off one key, subscription simply stops when the
             * paying stops. The type is what decides whether a key may be
             * reissued onto a different machine — a question this counter is
             * asked constantly.
             */
            $table->enum('licence_type', ['oem', 'retail', 'volume', 'subscription', 'trial'])
                ->default('retail');
            /**
             * The key itself. Unique per tenant: this table exists to stop the
             * same key going out of the door twice, and a uniqueness constraint
             * is the only version of that promise which survives two people
             * selling at the same moment.
             */
            $table->string('licence_key', 120);
            $table->unsignedInteger('seats')->default(1);
            $table->unsignedInteger('seats_used')->default(0);

            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            // The machine it was activated on. A volume key covers many, so this
            // records the primary install and the seat count carries the rest.
            $table->foreignId('asset_id')->nullable()->constrained('computer_it_assets')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('vendor', 120)->nullable();

            $table->date('valid_from')->nullable();
            // Null means perpetual — which is most of them, and is why this is
            // nullable rather than defaulted to some far-future date that would
            // eventually surface in an "expiring soon" list.
            $table->date('valid_to')->nullable();

            $table->enum('status', ['in_stock', 'allocated', 'expired', 'revoked'])->default('in_stock');
            $table->timestamp('allocated_at')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'licence_key'], 'computer_it_licences_key_unique');
            $table->index(['tenant_id', 'status'], 'computer_it_licences_status_idx');
            $table->index(['tenant_id', 'customer_id'], 'computer_it_licences_customer_idx');
            $table->index(['tenant_id', 'valid_to'], 'computer_it_licences_expiry_idx');
        });

        // --- 4. The call book -------------------------------------------------
        Schema::create('computer_it_service_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('call_number', 40);
            /**
             * Nullable in the database and required at the API — the same shape
             * the vehicle register uses. A call must be logged against someone,
             * but deleting a customer years later must not silently delete the
             * record of what was done for them.
             */
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('computer_it_assets')->nullOnDelete();
            // What the call is being claimed under, if anything. Resolved when
            // the call is logged and again when it converts, because cover can
            // lapse in between.
            $table->foreignId('contract_id')->nullable()->constrained('service_contracts')->nullOnDelete();
            $table->foreignId('warranty_id')->nullable()->constrained('computer_it_warranties')->nullOnDelete();

            $table->enum('type', ['onsite', 'remote', 'carry_in', 'installation', 'preventive'])
                ->default('onsite');
            $table->enum('source', ['phone', 'email', 'whatsapp', 'walk_in', 'portal'])->default('phone');
            $table->enum('priority', ['low', 'normal', 'high', 'critical'])->default('normal');
            /**
             * logged → scheduled → assigned → attended → resolved, with
             * converted (bench work opened) and cancelled available throughout.
             * `attended` is separate from `resolved` on purpose: an engineer who
             * has been to site and ordered a part has attended the call and not
             * resolved it, and collapsing the two is how a support desk loses
             * sight of the calls that are actually waiting on something.
             */
            $table->enum('status', [
                'logged', 'scheduled', 'assigned', 'attended', 'resolved', 'converted', 'cancelled',
            ])->default('logged');

            $table->timestamp('logged_at');
            /**
             * What was promised. Derived from priority when the call is logged
             * (ServiceCall::slaHours) but stored rather than recomputed, because
             * the promise made on the day is the promise that was broken —
             * re-prioritising a call afterwards must not quietly rewrite whether
             * the desk was late.
             */
            $table->timestamp('sla_due_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('attended_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->foreignId('assigned_to')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('site_address')->nullable();
            $table->string('contact_person', 100)->nullable();
            $table->string('contact_phone', 20)->nullable();

            $table->text('complaint');
            $table->text('resolution')->nullable();
            /**
             * Whether anyone is paying for this visit. Set from live cover when
             * the call is logged and honoured again at conversion — a free visit
             * under an AMC and a chargeable one look identical on the board
             * otherwise, which is how site visits stop being billed.
             */
            $table->boolean('is_chargeable')->default(true);

            // Set the moment a call becomes bench work. Both directions are
            // stored (service_jobs.it_service_call_id points back) so either
            // screen reaches the other without a join through a third table.
            $table->foreignId('service_job_id')->nullable()->constrained('service_jobs')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'call_number'], 'computer_it_calls_number_unique');
            $table->index(['tenant_id', 'status'], 'computer_it_calls_status_idx');
            // The desk's day view, and the "are we late?" list.
            $table->index(['tenant_id', 'scheduled_at'], 'computer_it_calls_day_idx');
            $table->index(['tenant_id', 'sla_due_at'], 'computer_it_calls_sla_idx');
            $table->index(['tenant_id', 'asset_id'], 'computer_it_calls_asset_idx');
        });

        // --- 5. Job-card columns on the service job ---------------------------
        Schema::table('service_jobs', function (Blueprint $table) {
            /**
             * What is on the bench. Every column here is prefixed `it_` rather
             * than named for what it holds, because `service_jobs` is shared by
             * every repair trade and a bare `asset_id` would be read as the
             * company's own fixed asset by the next person along — and a bare
             * `warranty_id` is already taken by the Automobile plugin's register.
             */
            $table->foreignId('it_asset_id')->nullable()->after('customer_id')
                ->constrained('computer_it_assets')->nullOnDelete();
            $table->foreignId('it_service_call_id')->nullable()->after('it_asset_id')
                ->constrained('computer_it_service_calls')->nullOnDelete();
            /**
             * The cover being claimed against, when the job is warranty work.
             * Distinct from the `is_warranty` flag, which only says the customer
             * is not paying: this says *why*, and is what makes a claim
             * auditable when the manufacturer asks months later.
             */
            $table->foreignId('it_warranty_id')->nullable()->after('it_service_call_id')
                ->constrained('computer_it_warranties')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('it_warranty_id');
            $table->dropConstrainedForeignId('it_service_call_id');
            $table->dropConstrainedForeignId('it_asset_id');
        });

        Schema::dropIfExists('computer_it_service_calls');
        Schema::dropIfExists('computer_it_licence_keys');
        Schema::dropIfExists('computer_it_warranties');
        Schema::dropIfExists('computer_it_assets');
    }
};
