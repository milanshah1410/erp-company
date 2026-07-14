<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tailor (job-work / piece-rate) module.
 *
 * A tailor is a subcontractor paid per piece. Work is issued against a catalog
 * product (the "style"); the per-piece rate is the sum of the operation rates
 * on that style's rate card (cutting + stitching + finishing, …). Completing
 * work accrues earnings (ledger credit); payments settle them (ledger debit).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Tailor master.
        Schema::create('tailors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('mobile')->nullable();
            $table->text('address')->nullable();
            $table->string('skill')->nullable();
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        // Rate card: one per style (catalog product) holding its standard rate.
        Schema::create('rate_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('standard_rate', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'product_id']);
        });

        // Piece rate: an operation within a rate card (cutting, stitching, …).
        Schema::create('piece_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rate_card_id')->constrained('rate_cards')->cascadeOnDelete();
            $table->string('operation');
            $table->decimal('rate', 12, 2)->default(0);
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'rate_card_id']);
        });

        // Work issued to a tailor (the job card).
        Schema::create('tailor_works', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('work_number');
            $table->foreignId('tailor_id')->constrained('tailors')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('rate_card_id')->nullable()->constrained('rate_cards')->nullOnDelete();
            $table->decimal('issued_qty', 12, 2);
            $table->decimal('completed_qty', 12, 2)->default(0);
            $table->decimal('rate', 12, 2)->default(0); // per-piece rate snapshot at issue time
            $table->enum('status', ['pending', 'partial', 'completed', 'cancelled'])->default('pending');
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'work_number']);
            $table->index(['tenant_id', 'status']);
        });

        // A completion event against a work order (ledger credit / earning).
        Schema::create('tailor_work_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tailor_work_id')->constrained('tailor_works')->cascadeOnDelete();
            $table->foreignId('tailor_id')->constrained('tailors')->cascadeOnDelete();
            $table->decimal('qty', 12, 2);
            $table->decimal('rate', 12, 2);
            $table->decimal('amount', 15, 2);
            $table->date('completed_at');
            $table->boolean('received_to_stock')->default(false);
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'tailor_id']);
        });

        // A payout to a tailor (ledger debit).
        Schema::create('tailor_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tailor_id')->constrained('tailors')->cascadeOnDelete();
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
        Schema::dropIfExists('tailor_payments');
        Schema::dropIfExists('tailor_work_completions');
        Schema::dropIfExists('tailor_works');
        Schema::dropIfExists('piece_rates');
        Schema::dropIfExists('rate_cards');
        Schema::dropIfExists('tailors');
    }
};
