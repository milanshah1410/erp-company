<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the tailor (job-work) module with the pieces that the base module
 * left out: QC rejection on completions, contractor grouping over tailors,
 * post-delivery alteration tracking and performance bonuses.
 *
 *  - Rejected pieces: a completion now records how many of the submitted pieces
 *    failed QC. Rejected pieces are never paid; each is either reworked (returns
 *    to the pending pool) or scrapped (written off so the order can still close).
 *    tailor_works carries cumulative rejected/scrapped tallies for reporting and
 *    the completion math (remaining = issued - completed - scrapped).
 *  - Contractors: a subcontractor who supplies several tailors. Tailors point at
 *    an optional contractor; payments stay per-tailor, the contractor rolls up.
 *  - Alterations: pieces sent back for adjustment after delivery.
 *  - Bonuses: rule-assisted or manual performance payouts that credit the tailor
 *    ledger once approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Contractor master (must exist before the tailors FK below).
        Schema::create('contractors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('mobile')->nullable();
            $table->text('address')->nullable();
            $table->string('gstin')->nullable();
            $table->string('pan')->nullable();
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        // Group each tailor under an optional contractor.
        Schema::table('tailors', function (Blueprint $table) {
            $table->foreignId('contractor_id')->nullable()->after('tenant_id')
                ->constrained('contractors')->nullOnDelete();
        });

        // QC rejection recorded on each completion event.
        Schema::table('tailor_work_completions', function (Blueprint $table) {
            $table->decimal('rejected_qty', 12, 2)->default(0)->after('qty');
            $table->enum('reject_action', ['rework', 'scrap'])->nullable()->after('rejected_qty');
            $table->string('rejection_reason')->nullable()->after('reject_action');
        });

        // Cumulative reject / scrap tallies on the work order.
        Schema::table('tailor_works', function (Blueprint $table) {
            $table->decimal('rejected_qty', 12, 2)->default(0)->after('completed_qty');
            $table->decimal('scrapped_qty', 12, 2)->default(0)->after('rejected_qty');
        });

        // Post-delivery alterations.
        Schema::create('tailor_alterations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('alteration_number');
            $table->foreignId('tailor_work_id')->nullable()->constrained('tailor_works')->nullOnDelete();
            $table->foreignId('tailor_id')->nullable()->constrained('tailors')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->decimal('qty', 12, 2)->default(1);
            $table->string('reason');
            $table->enum('status', ['pending', 'in_progress', 'done'])->default('pending');
            $table->decimal('charge', 12, 2)->default(0);
            $table->date('reported_date');
            $table->date('completed_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'alteration_number']);
            $table->index(['tenant_id', 'status']);
        });

        // Performance bonuses (ledger credit once approved).
        Schema::create('tailor_bonuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tailor_id')->constrained('tailors')->cascadeOnDelete();
            $table->string('bonus_number');
            $table->enum('basis', ['pieces', 'efficiency', 'ontime', 'manual'])->default('manual');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('pieces', 12, 2)->nullable();
            $table->decimal('rate', 12, 2)->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->enum('status', ['draft', 'approved', 'paid'])->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'bonus_number']);
            $table->index(['tenant_id', 'tailor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tailor_bonuses');
        Schema::dropIfExists('tailor_alterations');

        Schema::table('tailor_works', function (Blueprint $table) {
            $table->dropColumn(['rejected_qty', 'scrapped_qty']);
        });

        Schema::table('tailor_work_completions', function (Blueprint $table) {
            $table->dropColumn(['rejected_qty', 'reject_action', 'rejection_reason']);
        });

        Schema::table('tailors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contractor_id');
        });

        Schema::dropIfExists('contractors');
    }
};
