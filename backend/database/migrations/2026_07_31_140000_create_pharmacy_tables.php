<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pharmacy / Medical Store: scheduled drugs and the prescriptions that let a
 * counter sell them.
 *
 * Under India's Drugs and Cosmetics Rules a chemist may not hand over a
 * Schedule H, H1 or X medicine without a registered practitioner's
 * prescription, and for H1 and X must keep a bound register of every such sale
 * — patient, prescriber, drug, quantity, date — retained for three years. That
 * register is the reason this is an ERP feature and not a note field: it is
 * produced during a drug-inspector visit, so it has to be complete and
 * reconstructable from the sales themselves.
 *
 * So: products carry their schedule, prescriptions are first-class records tied
 * to a customer, and a sale of a scheduled item is refused unless one is
 * attached (PharmacyService owns that rule).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            /**
             * Drug schedule under the Drugs and Cosmetics Rules, 1945:
             *   none — general / OTC, no prescription needed
             *   g    — "Caution: to be taken under medical supervision"
             *   h    — prescription only
             *   h1   — prescription only + mandatory register, 3-year retention
             *   x    — narcotic / psychotropic: special licence + register
             */
            $table->enum('drug_schedule', ['none', 'g', 'h', 'h1', 'x'])
                ->default('none')->after('is_service');
            // Salt / generic name — what a chemist actually substitutes on.
            $table->string('composition')->nullable()->after('drug_schedule');
            $table->string('manufacturer')->nullable()->after('composition');

            $table->index(['tenant_id', 'drug_schedule']);
        });

        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('prescription_number');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            // The patient is not always the account holder — a family buys on
            // one customer record but the register names who the drug is for.
            $table->string('patient_name');
            $table->unsignedSmallInteger('patient_age')->nullable();
            $table->enum('patient_gender', ['male', 'female', 'other'])->nullable();
            $table->string('patient_phone', 30)->nullable();

            // The prescriber. Registration number is what makes the entry
            // auditable, so it is stored separately from the name.
            $table->string('doctor_name');
            $table->string('doctor_registration_no')->nullable();
            $table->string('hospital')->nullable();

            $table->date('prescribed_on');
            // Repeat prescriptions are dispensed more than once; a null date
            // means "single use", which the service treats as expiring at first
            // dispense.
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            // Scan of the paper slip — the register is stronger with the
            // original attached.
            $table->string('attachment_path')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'prescription_number']);
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'prescribed_on']);
        });

        // The dispensing log. One row per scheduled line actually handed over —
        // this is what the H1/X register is rendered from, which is why it
        // stores its own snapshot of drug, schedule and quantity rather than
        // reading through to the product: a later rename or reclassification
        // must not rewrite history an inspector already saw.
        Schema::create('prescription_dispenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prescription_id')->constrained('prescriptions')->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('batches')->nullOnDelete();

            $table->string('product_name');
            $table->string('drug_schedule', 4);
            $table->decimal('quantity', 15, 3);
            $table->date('dispensed_on');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'dispensed_on']);
            // Named explicitly: the generated name for this three-column index
            // is 65 characters and MySQL caps identifiers at 64.
            $table->index(['tenant_id', 'drug_schedule', 'dispensed_on'], 'pd_tenant_schedule_date_idx');
        });

        // The prescription a sale was dispensed against, so an invoice can be
        // traced back to its authority.
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->foreignId('prescription_id')->nullable()->after('customer_id')
                ->constrained('prescriptions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prescription_id');
        });

        Schema::dropIfExists('prescription_dispenses');
        Schema::dropIfExists('prescriptions');

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'drug_schedule']);
            $table->dropColumn(['drug_schedule', 'composition', 'manufacturer']);
        });
    }
};
