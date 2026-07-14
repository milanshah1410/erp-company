<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Job Work (outside-vendor subcontracting): raw material is issued to an
 * outside vendor (a Supplier), processed, and the finished goods are received
 * back. Each material line reconciles issued vs received vs damage vs shortage,
 * with the remainder pending at the vendor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_works', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('job_work_number');
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->enum('status', ['draft', 'issued', 'partial', 'completed', 'closed', 'cancelled'])->default('draft');
            $table->string('job_type')->nullable();
            $table->date('job_work_date');
            $table->date('expected_date')->nullable();
            $table->date('issued_date')->nullable();
            $table->date('completed_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'job_work_number']);
            $table->index('status');
        });

        Schema::create('job_work_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_work_id')->constrained('job_works')->cascadeOnDelete();
            // Raw material issued to the vendor.
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            // Finished good received back — may differ from the issued material
            // (e.g. fabric issued → garment received). Null means "same as issued".
            $table->foreignId('finished_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('finished_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->decimal('issued_qty', 15, 3);
            $table->decimal('received_qty', 15, 3)->default(0);
            $table->decimal('damage_qty', 15, 3)->default(0);
            $table->decimal('shortage_qty', 15, 3)->default(0);
            // Raw issue cost snapshotted at issue; finished cost booked at receipt.
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('finished_unit_cost', 15, 4)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_work_items');
        Schema::dropIfExists('job_works');
    }
};
