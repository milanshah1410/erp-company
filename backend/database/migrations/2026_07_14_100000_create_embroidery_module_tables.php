<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Embroidery (job-work) module.
 *
 * An embroidery vendor is a subcontractor: greige/cut pieces are DISPATCHED to
 * them against a design (design number + stitch count), embroidered at an agreed
 * per-piece cost, and RECEIVED back. "Pending" is the quantity dispatched but not
 * yet received. Receiving accrues the vendor's earning (ledger credit); payments
 * settle it (ledger debit). Mirrors the tailor job-work module.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Embroidery vendor master.
        Schema::create('embroidery_vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('mobile')->nullable();
            $table->text('address')->nullable();
            $table->string('gst_number')->nullable();
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        // Dispatch (the embroidery job card): goods sent to a vendor for a design.
        Schema::create('embroidery_works', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('work_number');
            $table->foreignId('embroidery_vendor_id')->constrained('embroidery_vendors')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('design_number');
            $table->unsignedInteger('stitch_count')->nullable();
            $table->decimal('dispatched_qty', 12, 2);
            $table->decimal('received_qty', 12, 2)->default(0);
            $table->decimal('cost', 12, 2)->default(0); // per-piece embroidery charge
            $table->enum('status', ['pending', 'partial', 'completed', 'cancelled'])->default('pending');
            $table->date('dispatch_date');
            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'work_number']);
            $table->index(['tenant_id', 'status']);
        });

        // A receive event against a dispatch (ledger credit / earning).
        Schema::create('embroidery_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('embroidery_work_id')->constrained('embroidery_works')->cascadeOnDelete();
            $table->foreignId('embroidery_vendor_id')->constrained('embroidery_vendors')->cascadeOnDelete();
            $table->decimal('qty', 12, 2);
            $table->decimal('cost', 12, 2);
            $table->decimal('amount', 15, 2);
            $table->date('received_at');
            $table->boolean('received_to_stock')->default(false);
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'embroidery_vendor_id']);
        });

        // A payout to an embroidery vendor (ledger debit).
        Schema::create('embroidery_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('embroidery_vendor_id')->constrained('embroidery_vendors')->cascadeOnDelete();
            $table->string('payment_number');
            $table->decimal('amount', 15, 2);
            $table->enum('mode', ['cash', 'bank', 'upi', 'adjustment'])->default('cash');
            $table->date('payment_date');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'payment_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('embroidery_payments');
        Schema::dropIfExists('embroidery_receipts');
        Schema::dropIfExists('embroidery_works');
        Schema::dropIfExists('embroidery_vendors');
    }
};
