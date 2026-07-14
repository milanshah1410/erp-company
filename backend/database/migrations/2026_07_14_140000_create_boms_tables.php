<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bill of Materials — a reusable recipe describing the raw-material components
 * that go into producing a finished product. A manufacturing order can be
 * seeded from a BOM (its components exploded to the order quantity) instead of
 * keying materials by hand. One product may have several BOMs (revisions /
 * alternates); one is flagged the default.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Header: the recipe for a finished product, yielding output_quantity
        // units per run. Components are scaled from that base quantity.
        Schema::create('boms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('bom_number');
            $table->string('name');
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->decimal('output_quantity', 15, 3)->default(1); // units produced per full recipe
            $table->boolean('is_default')->default(false);          // the preferred BOM for the product
            $table->boolean('is_active')->default(true);
            $table->decimal('material_cost', 15, 2)->default(0);    // snapshot roll-up of component costs
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'bom_number']);
            $table->index(['tenant_id', 'product_id']);
        });

        // Component lines: one raw material per row, quantity expressed per the
        // header's output_quantity, with an optional wastage allowance.
        Schema::create('bom_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bom_id')->constrained('boms')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->decimal('quantity', 15, 3);                 // per output_quantity of the finished good
            $table->decimal('wastage_percent', 8, 3)->default(0); // extra allowance added on explosion
            $table->decimal('unit_cost', 15, 4)->default(0);    // snapshot; defaults to product cost price
            $table->decimal('total_cost', 15, 2)->default(0);
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        // Optional link from a manufacturing order back to the BOM it was seeded
        // from — for traceability, not a hard dependency.
        Schema::table('manufacturing_orders', function (Blueprint $table) {
            $table->foreignId('bom_id')->nullable()->after('variant_id')
                ->constrained('boms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('manufacturing_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bom_id');
        });
        Schema::dropIfExists('bom_items');
        Schema::dropIfExists('boms');
    }
};
