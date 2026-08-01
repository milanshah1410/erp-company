<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automobile plugin schema: the vehicle register, the appointment book, the
 * warranty register, and the columns that turn a service job into a job card.
 *
 * The organising idea is that a workshop does not serve customers, it serves
 * *vehicles*. The same owner brings in two cars; the same car changes hands and
 * keeps its history. Everything a garage argues about — is this still under
 * warranty, when was the last oil change, whose fault is the noise — is answered
 * against the vehicle, not the person who happens to be holding the keys. So the
 * vehicle is the master record and the customer hangs off it, not the reverse.
 *
 * Four groups of change:
 *
 *  1. automobile_vehicles — the VIN register. Separate from the existing
 *     `vehicles` table on purpose: that one is the company's own fleet (what
 *     fuel and dispatch expenses are booked against), and merging the two would
 *     put a customer's Swift in the dispatch planner's vehicle picker.
 *
 *  2. automobile_bookings — the appointment book. A booking is not a job card:
 *     most bookings never become work (no-shows, reschedules, phone enquiries),
 *     and a workshop that created a job card per phone call would have a
 *     worksheet full of vehicles that never arrived. Converting is an explicit
 *     act, and the two rows stay linked afterwards.
 *
 *  3. automobile_warranties — what is still covered, and until when. Both
 *     limbs of a real warranty are here: time (end_date) and distance
 *     (coverage_km from start_odometer), because whichever runs out first ends
 *     the cover, and a register that only tracks dates will bill for work that
 *     should have been free — or, worse, give away work that should not.
 *
 *  4. Job-card columns on service_jobs. A job card *is* a service job — same
 *     lifecycle, same spare-part issue, same billing through the sales spine —
 *     with a vehicle, an odometer reading and possibly a warranty behind it.
 *     Adding those columns is far more honest than a parallel table that would
 *     have to re-implement parts issue and invoicing to be useful.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- 1. The vehicle register ----------------------------------------
        Schema::create('automobile_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            /**
             * Nullable because a dealer's stock vehicle has no owner yet — it is
             * on the floor with a VIN and no keys handed over. nullOnDelete so
             * removing a customer does not erase the vehicle's service history.
             */
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            // Both identifiers are nullable: a vehicle awaiting registration has
            // a VIN and no plate, and an old vehicle taken in for repair often
            // arrives with a plate and nobody willing to crawl under it for the
            // chassis number.
            $table->string('registration_no', 20)->nullable();
            $table->string('vin', 17)->nullable();
            $table->string('engine_no', 30)->nullable();

            $table->string('make', 60)->nullable();
            $table->string('model', 60)->nullable();
            $table->string('variant', 60)->nullable();
            $table->unsignedSmallInteger('manufacture_year')->nullable();
            $table->enum('fuel_type', ['petrol', 'diesel', 'cng', 'lpg', 'electric', 'hybrid'])->nullable();
            $table->enum('transmission', ['manual', 'automatic', 'amt', 'cvt'])->nullable();
            $table->string('colour', 40)->nullable();

            /**
             * Last reading anyone recorded, and when. Kept on the vehicle rather
             * than derived from the newest job card because readings also arrive
             * from bookings and from a counter sale, and a workshop asking "how
             * far has it done?" should not have to care which door it came in
             * through.
             */
            $table->unsignedInteger('odometer')->default(0);
            $table->timestamp('odometer_updated_at')->nullable();

            $table->date('registration_date')->nullable();
            // When this shop handed it over, if this shop sold it.
            $table->date('delivery_date')->nullable();
            $table->foreignId('sale_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->string('insurance_provider', 100)->nullable();
            $table->string('insurance_policy_no', 60)->nullable();
            $table->date('insurance_expiry')->nullable();
            $table->date('puc_expiry')->nullable();
            $table->date('fitness_expiry')->nullable();

            /**
             * The service schedule, in whichever unit the manufacturer states —
             * usually both, whichever falls first. Nullable because a shop that
             * does not chase service reminders should not be forced to invent a
             * number, and next_service_due_at simply stays empty.
             */
            $table->unsignedSmallInteger('service_interval_days')->nullable();
            $table->unsignedInteger('service_interval_km')->nullable();
            $table->timestamp('last_service_at')->nullable();
            $table->date('next_service_due_at')->nullable();
            $table->unsignedInteger('next_service_odometer')->nullable();

            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            /**
             * One row per identifier, per tenant. Duplicate vehicles are the
             * failure mode that ruins a workshop's history: the second job card
             * goes on the second row, and the "last serviced" the advisor reads
             * out at the counter is wrong. MySQL allows repeated NULLs in a
             * unique index, so the unregistered and chassis-less cases still fit.
             */
            $table->unique(['tenant_id', 'vin'], 'automobile_vehicles_vin_unique');
            $table->unique(['tenant_id', 'registration_no'], 'automobile_vehicles_reg_unique');
            $table->index(['tenant_id', 'customer_id'], 'automobile_vehicles_customer_idx');
            $table->index(['tenant_id', 'next_service_due_at'], 'automobile_vehicles_due_idx');
        });

        // --- 2. The appointment book ----------------------------------------
        Schema::create('automobile_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('booking_number', 40);
            $table->foreignId('vehicle_id')->constrained('automobile_vehicles')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            $table->enum('service_type', [
                'general', 'free_service', 'running_repair', 'accident',
                'warranty', 'inspection', 'body_paint',
            ])->default('general');
            $table->enum('source', ['walk_in', 'phone', 'online', 'whatsapp', 'campaign'])->default('phone');
            /**
             * booked → confirmed → arrived → converted, with no_show and
             * cancelled available throughout. `converted` is terminal and means a
             * job card exists; nothing else closes the slot, so the day view can
             * tell "we are still expecting this" from "this is on the ramp".
             */
            $table->enum('status', ['booked', 'confirmed', 'arrived', 'converted', 'no_show', 'cancelled'])
                ->default('booked');

            $table->timestamp('scheduled_at');
            $table->timestamp('estimated_ready_at')->nullable();
            // The service advisor who owns the slot, and the branch/bay it sits in.
            $table->foreignId('assigned_to')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();

            // Declared over the phone; the reading taken at the gate goes on the
            // job card instead, which is why the two are not the same column.
            $table->unsignedInteger('odometer')->nullable();
            $table->text('complaint')->nullable();
            $table->decimal('estimated_cost', 15, 2)->default(0);

            $table->boolean('pickup_required')->default(false);
            $table->text('pickup_address')->nullable();

            // Set the moment the booking becomes work. Both directions are
            // stored (service_jobs.booking_id points back) so either screen can
            // reach the other without a join through a third table.
            $table->foreignId('service_job_id')->nullable()->constrained('service_jobs')->nullOnDelete();
            $table->timestamp('reminder_sent_at')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'booking_number'], 'automobile_bookings_number_unique');
            // The day view: "what is coming in tomorrow", in time order.
            $table->index(['tenant_id', 'scheduled_at'], 'automobile_bookings_day_idx');
            $table->index(['tenant_id', 'status'], 'automobile_bookings_status_idx');
            $table->index(['tenant_id', 'vehicle_id'], 'automobile_bookings_vehicle_idx');
        });

        // --- 3. The warranty register ---------------------------------------
        Schema::create('automobile_warranties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('warranty_number', 40);
            /**
             * Three genuinely different things are warranted in this trade: the
             * vehicle (manufacturer cover), a part fitted to it (battery, tyre,
             * clutch plate) and the labour of a repair (the shop's own promise
             * that the job it just did will hold). They share every field that
             * matters, so they share a table and differ by type.
             */
            $table->enum('type', ['vehicle', 'part', 'service'])->default('vehicle');
            $table->enum('provider', ['manufacturer', 'dealer', 'third_party'])->default('manufacturer');

            $table->foreignId('vehicle_id')->nullable()->constrained('automobile_vehicles')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            // The part, for a part warranty, plus whatever serial is stamped on it.
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('serial_no', 100)->nullable();
            // What it was sold on, so a claim can be traced back to the money.
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('service_job_id')->nullable()->constrained('service_jobs')->nullOnDelete();

            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('coverage_months')->nullable();
            /**
             * The distance limb. Cover ends at whichever of end_date and
             * (start_odometer + coverage_km) is reached first — that is how every
             * real warranty in this trade is written, and a register tracking
             * only dates will quietly hand out free work on a taxi that did three
             * years of running in eleven months.
             */
            $table->unsignedInteger('coverage_km')->nullable();
            $table->unsignedInteger('start_odometer')->nullable();

            $table->enum('status', ['active', 'expired', 'claimed', 'void'])->default('active');
            /**
             * Claims are counted, not listed: the claim itself *is* a job card
             * (service_jobs.warranty_id), so a claims table would be a second,
             * emptier copy of work that already exists. These two columns exist
             * so the register can be read without touching the job book.
             */
            $table->unsignedInteger('claims_count')->default(0);
            $table->timestamp('last_claim_at')->nullable();

            $table->text('terms')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'warranty_number'], 'automobile_warranties_number_unique');
            $table->index(['tenant_id', 'vehicle_id'], 'automobile_warranties_vehicle_idx');
            $table->index(['tenant_id', 'status', 'end_date'], 'automobile_warranties_expiry_idx');
        });

        // --- 4. Job-card columns on the service job -------------------------
        Schema::table('service_jobs', function (Blueprint $table) {
            /**
             * What is on the ramp. Nullable because the same table still serves
             * every other repair trade — an AC unit has no VIN — and nullOnDelete
             * because deleting a vehicle must not take its billed work with it.
             */
            $table->foreignId('vehicle_id')->nullable()->after('customer_id')
                ->constrained('automobile_vehicles')->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->after('vehicle_id')
                ->constrained('automobile_bookings')->nullOnDelete();
            /**
             * The cover being claimed against, when the job is warranty work.
             * Distinct from the existing `is_warranty` flag, which only says the
             * customer is not paying: this says *why*, and is what makes a claim
             * auditable months later.
             */
            $table->foreignId('warranty_id')->nullable()->after('booking_id')
                ->constrained('automobile_warranties')->nullOnDelete();

            // In at the gate, out at delivery. Both stored because the pair is
            // the only evidence of how far a vehicle was driven while in the
            // shop's custody, which is a question that does get asked.
            $table->unsignedInteger('odometer_in')->nullable()->after('item_serial');
            $table->unsignedInteger('odometer_out')->nullable()->after('odometer_in');
        });
    }

    public function down(): void
    {
        Schema::table('service_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warranty_id');
            $table->dropConstrainedForeignId('booking_id');
            $table->dropConstrainedForeignId('vehicle_id');
            $table->dropColumn(['odometer_in', 'odometer_out']);
        });

        Schema::dropIfExists('automobile_warranties');
        Schema::dropIfExists('automobile_bookings');
        Schema::dropIfExists('automobile_vehicles');
    }
};
