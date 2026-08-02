<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optical & Eyewear plugin schema.
 *
 * Core already owns the eye test, the lens specification and the spectacle
 * order (2026_07_31_160000_create_optical_tables). Nothing here duplicates any
 * of it — every change is a column on one of those tables, or the one register
 * core had no reason to hold. Five groups:
 *
 *  1. Frame details on `products`. A frame was a boolean, which is not enough to
 *     sell one. The trade speaks in the boxed marking stamped inside the temple
 *     — 52▫18-140, meaning lens width, bridge and temple length in millimetres —
 *     plus shape, rim and material, and a customer asks for exactly those. On
 *     the product rather than a frame master because a frame *is* an article the
 *     shop buys, prices, barcodes and counts; a parallel master would be a
 *     second record of one thing, free to disagree about the price.
 *
 *  2. The prescription attachment. Powers are keyed — that stays, because the
 *     lab is sent numbers and a photograph cannot be validated in quarter
 *     dioptres — but the paper the customer walked in with is now kept beside
 *     them. It is what settles an argument about what was ordered, and it is
 *     patient material, so it lives on the tenant's private disk and is served
 *     through a signed URL, never a public one.
 *
 *  3. `optical_appointments` — the diary. A slot is not an order and most slots
 *     never become one: people ring to ask, reschedule, and half do not turn up.
 *     A shop that opened an order per enquiry would have a workshop list full of
 *     spectacles nobody asked for. The two stay linked once a test happens, so
 *     either screen reaches the other.
 *
 *  4. Collection columns on `optical_orders`. `delivered_at` recorded *that* a
 *     pair was handed over; these record the things a shop is actually asked
 *     afterwards — was the customer ever told it was ready, and who took it.
 *     Someone other than the patient collects far more often than not, and "a
 *     man who said he was her husband" is not an answer.
 *
 *  5. `optical_order_id` on `product_warranties`. The same move Electronics made
 *     with `unit_id` and Mobile with `handset_id`: core's register is the right
 *     one and gets a column, rather than this vertical shipping a second
 *     warranty screen that would disagree with it. A card issued on handover
 *     traces to the pair even before the invoice exists.
 *
 * Dated after 2026_08_03_100000 (product_warranties) and 2026_07_31_160000
 * (the optical core), both of which group 5 and groups 1/2/4 alter.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- 1. Frame details ------------------------------------------------
        Schema::table('products', function (Blueprint $table) {
            /**
             * What the frame looks like and what it is made of. Nullable
             * throughout and meaningless on anything that is not a frame — the
             * existing `is_frame` flag is still what says whether this article
             * belongs in the order form's frame slot, and these describe it once
             * it does.
             */
            $table->enum('frame_shape', [
                'rectangle', 'square', 'round', 'oval', 'cat_eye', 'aviator', 'wayfarer', 'wrap', 'geometric',
            ])->nullable()->after('is_frame');
            // Rimless and half-rim frames cannot take every lens: a thin blank
            // drilled at the edge chips, which is why the fitter needs this on
            // the tray label and not only in the buyer's head.
            $table->enum('frame_rim', ['full_rim', 'half_rim', 'rimless'])->nullable()->after('frame_shape');
            $table->enum('frame_material', [
                'acetate', 'metal', 'titanium', 'tr90', 'stainless_steel', 'alloy', 'wood', 'combination',
            ])->nullable()->after('frame_rim');
            $table->enum('frame_gender', ['men', 'women', 'unisex', 'kids'])->nullable()->after('frame_material');
            $table->string('frame_colour', 60)->nullable()->after('frame_gender');

            /**
             * The boxed marking, in millimetres. Three separate columns rather
             * than the printed "52▫18-140" string because a shop searches on
             * them: someone with a narrow face is looking for an eye size under
             * 50, and that is a range query, not a text match.
             */
            $table->unsignedSmallInteger('frame_eye_size')->nullable()->after('frame_colour');
            $table->unsignedSmallInteger('frame_bridge_size')->nullable()->after('frame_eye_size');
            $table->unsignedSmallInteger('frame_temple_length')->nullable()->after('frame_bridge_size');

            // The tray it hangs on. A frame is found by walking to it, and a
            // shop with four hundred frames cannot do that from a SKU alone.
            $table->string('frame_tray', 40)->nullable()->after('frame_temple_length');

            // "Show me the small metal cat-eyes" — the three filters a counter
            // actually uses, together.
            $table->index(['tenant_id', 'is_frame', 'frame_shape'], 'products_frame_shape_idx');
        });

        // --- 2. The prescription the customer brought in ----------------------
        Schema::table('eye_prescriptions', function (Blueprint $table) {
            // Path on the tenant's private disk (App\Support\TenantStorage), not
            // a URL: the bucket is shared and folder-scoped per tenant, and a
            // stored URL would outlive the signature that made it readable.
            $table->string('attachment_path')->nullable()->after('notes');
            // What the customer's file was called. Kept so a download hands back
            // something recognisable rather than a hashed name.
            $table->string('attachment_name')->nullable()->after('attachment_path');
            $table->timestamp('attachment_uploaded_at')->nullable()->after('attachment_name');
        });

        // --- 3. The appointment book -----------------------------------------
        Schema::create('optical_appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('appointment_number', 40);

            /**
             * Nullable customer: most people ring before they are anybody's
             * account, and forcing a customer record to book a slot means the
             * register fills with one-line ghosts for tests that never happened.
             * The name and phone are held on the row itself for exactly that
             * reason.
             */
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('patient_name');
            $table->string('patient_phone', 30)->nullable();
            $table->unsignedSmallInteger('patient_age')->nullable();

            /**
             * What the slot is for. It is not cosmetic: an eye test occupies the
             * optometrist and a collection does not, so the clash rule
             * (AppointmentBook) only defends the ones that need a person.
             */
            $table->enum('purpose', [
                'eye_test', 'fitting', 'collection', 'adjustment', 'repair', 'follow_up',
            ])->default('eye_test');

            /**
             * Lifecycle. `arrived` is separate from `completed` because the wait
             * between them is the number a busy shop is judged on, and `no_show`
             * is separate from `cancelled` because one is the customer telling
             * you and the other is the customer not.
             */
            $table->enum('status', [
                'booked', 'confirmed', 'arrived', 'completed', 'no_show', 'cancelled',
            ])->default('booked');

            $table->timestamp('scheduled_at');
            // How long the slot holds. Twenty minutes is a refraction; a
            // collection is five. Stored per row because the shop decides.
            $table->unsignedSmallInteger('duration_minutes')->default(20);

            // Who is expected to do it. Nullable — a single-optometrist shop has
            // nobody to choose between, and the clash rule falls back to the
            // whole shop's diary when it is not set.
            $table->foreignId('optometrist_id')->nullable()->constrained('employees')->nullOnDelete();
            // Which branch. A chain books per shop, and a slot at another branch
            // is not a clash.
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();

            /**
             * What came of it, in both directions. An eye-test slot yields a
             * prescription; a fitting or collection slot was booked against a
             * pair that already exists. Both nullable, and both are how the
             * diary stops being a dead-end list of names.
             */
            $table->foreignId('eye_prescription_id')->nullable()->constrained('eye_prescriptions')->nullOnDelete();
            $table->foreignId('optical_order_id')->nullable()->constrained('optical_orders')->nullOnDelete();

            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'appointment_number'], 'optical_appointments_number_unique');
            // The screen is a day view: "what is booked, in order".
            $table->index(['tenant_id', 'scheduled_at'], 'optical_appointments_day_idx');
            $table->index(['tenant_id', 'status'], 'optical_appointments_status_idx');
            // The clash check reads one person's diary for one day.
            $table->index(['tenant_id', 'optometrist_id', 'scheduled_at'], 'optical_appointments_who_idx');
        });

        // --- 4. Collection ----------------------------------------------------
        Schema::table('optical_orders', function (Blueprint $table) {
            /**
             * When the pair actually became collectable. Core stamps ordered,
             * received and delivered; it never stamped the moment the lenses
             * went into the frame, which is the clock the desk is measured on —
             * "ready eleven days, still in the drawer" is the number that
             * matters, not "ordered three weeks ago".
             */
            $table->timestamp('fitted_at')->nullable()->after('received_at');

            // Whether anyone has told the customer, and how often. A finished
            // pair sitting uncollected is almost always a call nobody made.
            $table->timestamp('notified_at')->nullable()->after('fitted_at');
            $table->unsignedSmallInteger('notify_count')->default(0)->after('notified_at');

            // How it leaves the shop. Counter collection is the overwhelming
            // default; the other two need an address, which is why they are a
            // mode rather than a free-text note.
            $table->enum('delivery_mode', ['counter', 'home', 'courier'])->default('counter')->after('notify_count');
            $table->string('delivery_address', 500)->nullable()->after('delivery_mode');
            // Docket or consignment number for a couriered pair.
            $table->string('delivery_reference', 80)->nullable()->after('delivery_address');

            /**
             * Who actually took it. Very often not the person whose eyes were
             * tested — a son, a driver, a neighbour — and the shop is asked
             * about it precisely when something has gone wrong.
             */
            $table->string('collected_by_name', 150)->nullable()->after('delivery_reference');
            $table->string('collected_by_phone', 30)->nullable()->after('collected_by_name');
            $table->text('handover_notes')->nullable()->after('collected_by_phone');

            // Set once, by the observer, when cover was granted for this pair.
            // It is the idempotency latch: a re-save of a delivered order must
            // not print a second card.
            $table->timestamp('warranty_issued_at')->nullable()->after('handover_notes');

            // The desk's own worklist: ready, not gone.
            $table->index(['tenant_id', 'status', 'fitted_at'], 'optical_orders_ready_idx');
        });

        // --- 5. The warranty card's pair --------------------------------------
        Schema::table('product_warranties', function (Blueprint $table) {
            $table->foreignId('optical_order_id')->nullable()->after('invoice_id')
                ->constrained('optical_orders')->nullOnDelete();
            $table->index(['tenant_id', 'optical_order_id'], 'product_warranties_optical_idx');
        });
    }

    public function down(): void
    {
        Schema::table('product_warranties', function (Blueprint $table) {
            $table->dropIndex('product_warranties_optical_idx');
            $table->dropConstrainedForeignId('optical_order_id');
        });

        Schema::table('optical_orders', function (Blueprint $table) {
            $table->dropIndex('optical_orders_ready_idx');
            $table->dropColumn([
                'fitted_at', 'notified_at', 'notify_count', 'delivery_mode', 'delivery_address',
                'delivery_reference', 'collected_by_name', 'collected_by_phone', 'handover_notes',
                'warranty_issued_at',
            ]);
        });

        Schema::dropIfExists('optical_appointments');

        Schema::table('eye_prescriptions', function (Blueprint $table) {
            $table->dropColumn(['attachment_path', 'attachment_name', 'attachment_uploaded_at']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_frame_shape_idx');
            $table->dropColumn([
                'frame_shape', 'frame_rim', 'frame_material', 'frame_gender', 'frame_colour',
                'frame_eye_size', 'frame_bridge_size', 'frame_temple_length', 'frame_tray',
            ]);
        });
    }
};
