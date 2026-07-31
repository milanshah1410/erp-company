<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Optical: eye power and lens details.
 *
 * A spectacle sale is made to order. The shop records the customer's eye
 * prescription, picks a frame and a lens, and sends the powers to a grinding
 * lab; the lenses come back, get fitted, and the customer collects.
 *
 * Two things shape this schema:
 *
 *  1. Powers are recorded per eye, and every optical value moves in quarter
 *     dioptres. That is not decoration — a lens ground to -2.30 instead of
 *     -2.25 is scrap, so OpticalService validates the step rather than trusting
 *     whatever was typed.
 *
 *  2. The order *snapshots* the powers it was made to. A customer's eyes change
 *     and the prescription gets superseded, but the spectacles on the bench were
 *     ground to the numbers that were on the order that day, and a dispute six
 *     months later is settled by those numbers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eye_prescriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('prescription_number');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            $table->string('patient_name');
            $table->unsignedSmallInteger('patient_age')->nullable();
            $table->enum('patient_gender', ['male', 'female', 'other'])->nullable();
            $table->string('patient_phone', 30)->nullable();

            // Who tested the eyes. Often the shop's own optometrist, which is
            // why this is not modelled as an outside doctor.
            $table->string('optometrist_name')->nullable();
            $table->string('optometrist_registration_no')->nullable();

            /**
             * What the glasses are for. Decides whether ADD is meaningful:
             * a distance-only pair has no near addition.
             */
            $table->enum('vision_type', ['distance', 'near', 'bifocal', 'progressive'])
                ->default('distance');

            // Right eye (OD). Sphere and cylinder are signed; axis is degrees.
            $table->decimal('right_sph', 5, 2)->nullable();
            $table->decimal('right_cyl', 5, 2)->nullable();
            $table->unsignedSmallInteger('right_axis')->nullable();
            $table->decimal('right_add', 4, 2)->nullable();
            // Left eye (OS).
            $table->decimal('left_sph', 5, 2)->nullable();
            $table->decimal('left_cyl', 5, 2)->nullable();
            $table->unsignedSmallInteger('left_axis')->nullable();
            $table->decimal('left_add', 4, 2)->nullable();

            // Pupillary distance in mm — needed to centre the lenses. Shops
            // record either one binocular figure or one per eye.
            $table->decimal('pd', 4, 1)->nullable();
            $table->decimal('right_pd', 4, 1)->nullable();
            $table->decimal('left_pd', 4, 1)->nullable();

            $table->date('prescribed_on');
            // Eye tests go stale; most shops re-test after a year or two.
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'prescription_number'], 'eye_rx_number_unique');
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'prescribed_on']);
        });

        // Lens details. Null lens_type means "not a lens" — a frame, a case, a
        // cleaning cloth — and the product behaves like any other stock item.
        Schema::table('products', function (Blueprint $table) {
            $table->enum('lens_type', ['single_vision', 'bifocal', 'progressive', 'zero_power'])
                ->nullable()->after('huid');
            $table->enum('lens_material', ['cr39', 'polycarbonate', 'trivex', 'high_index', 'glass'])
                ->nullable()->after('lens_type');
            // Refractive index — 1.50 through 1.74. Thinner glass costs more.
            $table->decimal('lens_index', 3, 2)->nullable()->after('lens_material');
            $table->enum('lens_coating', ['none', 'ar', 'blue_cut', 'photochromic', 'uv'])
                ->nullable()->after('lens_index');
            // The power range this lens can actually be made in. An order
            // outside it has to be refused, not quietly sent to the lab.
            $table->decimal('min_sph', 5, 2)->nullable()->after('lens_coating');
            $table->decimal('max_sph', 5, 2)->nullable()->after('min_sph');
            $table->decimal('max_cyl', 5, 2)->nullable()->after('max_sph');
            // Frames are the other half of the trade and need their own flag so
            // the order form can offer the right products in each slot.
            $table->boolean('is_frame')->default(false)->after('max_cyl');

            $table->index(['tenant_id', 'lens_type']);
        });

        Schema::create('optical_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('order_number');
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('eye_prescription_id')->nullable()->constrained('eye_prescriptions')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();

            $table->foreignId('frame_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('lens_product_id')->nullable()->constrained('products')->nullOnDelete();

            /**
             * Lifecycle. `at_lab` is separate from `ordered` because a shop
             * chases the lab on it, and `fitted` is separate from `received`
             * because lenses arrive before anyone edges them into the frame.
             */
            $table->enum('status', [
                'draft', 'ordered', 'at_lab', 'received', 'fitted', 'delivered', 'cancelled',
            ])->default('draft');

            // The powers this pair was actually ground to — snapshotted, so a
            // later re-test cannot restate what was made.
            $table->enum('vision_type', ['distance', 'near', 'bifocal', 'progressive'])->default('distance');
            $table->decimal('right_sph', 5, 2)->nullable();
            $table->decimal('right_cyl', 5, 2)->nullable();
            $table->unsignedSmallInteger('right_axis')->nullable();
            $table->decimal('right_add', 4, 2)->nullable();
            $table->decimal('left_sph', 5, 2)->nullable();
            $table->decimal('left_cyl', 5, 2)->nullable();
            $table->unsignedSmallInteger('left_axis')->nullable();
            $table->decimal('left_add', 4, 2)->nullable();
            $table->decimal('pd', 4, 1)->nullable();

            $table->decimal('frame_price', 15, 2)->default(0);
            $table->decimal('lens_price', 15, 2)->default(0);
            $table->decimal('fitting_charge', 15, 2)->default(0);
            $table->enum('discount_type', ['percent', 'flat'])->nullable();
            $table->decimal('discount_value', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('round_off', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);

            $table->date('promised_on')->nullable();
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->text('lab_notes')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'order_number'], 'optical_orders_number_unique');
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'customer_id']);
        });

        if ($this->isMysql()) {
            DB::statement("ALTER TABLE sales_orders MODIFY channel ENUM('standard','pos','b2b','ecommerce','service','project','optical') NOT NULL DEFAULT 'standard'");
        }
    }

    public function down(): void
    {
        if ($this->isMysql()) {
            DB::table('sales_orders')->where('channel', 'optical')->update(['channel' => 'standard']);
            DB::statement("ALTER TABLE sales_orders MODIFY channel ENUM('standard','pos','b2b','ecommerce','service','project') NOT NULL DEFAULT 'standard'");
        }

        Schema::dropIfExists('optical_orders');

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'lens_type']);
            $table->dropColumn([
                'lens_type', 'lens_material', 'lens_index', 'lens_coating',
                'min_sph', 'max_sph', 'max_cyl', 'is_frame',
            ]);
        });

        Schema::dropIfExists('eye_prescriptions');
    }

    private function isMysql(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
