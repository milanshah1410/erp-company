<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Textile-specific tracking (SRS §5): fabric "than" (roll) management with
 * roll length / GSM / width / shade / dye-lot, 4-point fabric inspection,
 * shrinkage records, and cutting (cutting-loss + leftover-fabric management).
 *
 * A fabric roll is a physical unit of a fabric product. Receiving a roll can
 * optionally post its length into inventory (fabric_roll_in); cutting draws
 * length off the roll — the productive + loss portions leave stock
 * (fabric_cut_out) while any reusable remnant is booked back as a leftover
 * roll (fabric_roll_in), keeping roll length and product stock in sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fabric_rolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('roll_number');
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();

            // Textile attributes.
            $table->string('shade_no')->nullable();
            $table->string('dye_lot')->nullable();
            $table->decimal('gsm', 8, 2)->nullable();          // grams per square metre
            $table->decimal('width', 8, 2)->nullable();         // fabric width
            $table->string('width_unit', 12)->default('inch');  // inch / cm
            $table->string('length_unit', 12)->default('meter'); // meter / yard

            // Length tracking (in length_unit).
            $table->decimal('initial_length', 12, 3)->default(0);
            $table->decimal('current_length', 12, 3)->default(0);
            $table->decimal('unit_cost', 15, 4)->default(0);

            $table->string('grade', 20)->nullable();            // set from inspection: A / B / C / reject
            $table->enum('status', ['available', 'partial', 'consumed', 'quarantined', 'scrapped'])->default('available');

            // Leftover management: a remnant cut off a parent roll.
            $table->boolean('is_leftover')->default(false);
            $table->foreignId('parent_roll_id')->nullable()->constrained('fabric_rolls')->nullOnDelete();

            $table->boolean('posted_to_stock')->default(false);
            $table->date('received_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'roll_number']);
            $table->index(['tenant_id', 'status']);
            $table->index('dye_lot');
        });

        Schema::create('fabric_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('inspection_number');
            $table->foreignId('fabric_roll_id')->constrained('fabric_rolls')->cascadeOnDelete();
            $table->decimal('inspected_length', 12, 3)->default(0); // length examined (length_unit)
            $table->decimal('inspected_width', 8, 2)->nullable();
            $table->decimal('total_points', 10, 2)->default(0);     // Σ defect points
            $table->decimal('points_per_100sqm', 8, 2)->default(0); // 4-point score
            $table->string('grade', 20)->nullable();                // A / B / C
            $table->enum('result', ['pending', 'pass', 'reject'])->default('pending');
            $table->string('inspector')->nullable();
            $table->date('inspection_date');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'inspection_number']);
        });

        Schema::create('fabric_inspection_defects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fabric_inspection_id')->constrained('fabric_inspections')->cascadeOnDelete();
            $table->string('defect_type');
            $table->enum('severity', ['minor', 'major', 'critical'])->default('minor');
            $table->string('size_range')->nullable();  // e.g. "3-6 inch"
            $table->decimal('points', 6, 2)->default(1); // 4-point value per occurrence
            $table->unsignedInteger('count')->default(1);
            $table->timestamps();
        });

        Schema::create('shrinkage_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('record_number');
            $table->foreignId('fabric_roll_id')->nullable()->constrained('fabric_rolls')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('process')->nullable();   // washing / dyeing / steaming / other
            $table->decimal('length_before', 12, 3)->default(0);
            $table->decimal('length_after', 12, 3)->default(0);
            $table->decimal('width_before', 8, 2)->nullable();
            $table->decimal('width_after', 8, 2)->nullable();
            $table->decimal('length_shrinkage_pct', 6, 2)->default(0);
            $table->decimal('width_shrinkage_pct', 6, 2)->default(0);
            $table->date('test_date');
            $table->string('tested_by')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'record_number']);
        });

        Schema::create('fabric_cuttings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('cutting_number');
            $table->foreignId('fabric_roll_id')->constrained('fabric_rolls')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->date('cut_date');
            // length_used = length_consumed + cutting_loss + leftover_length
            $table->decimal('length_used', 12, 3)->default(0);
            $table->decimal('length_consumed', 12, 3)->default(0); // into finished pieces
            $table->decimal('cutting_loss', 12, 3)->default(0);    // wastage
            $table->decimal('leftover_length', 12, 3)->default(0); // reusable remnant
            $table->unsignedInteger('pieces_produced')->default(0);
            $table->foreignId('leftover_roll_id')->nullable()->constrained('fabric_rolls')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'cutting_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fabric_cuttings');
        Schema::dropIfExists('shrinkage_records');
        Schema::dropIfExists('fabric_inspection_defects');
        Schema::dropIfExists('fabric_inspections');
        Schema::dropIfExists('fabric_rolls');
    }
};
