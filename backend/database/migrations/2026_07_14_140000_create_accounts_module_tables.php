<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts module.
 *
 * Two write surfaces feed the derived account books (Cash Book, Bank Book,
 * Journal, Day Book):
 *
 *  - bank_accounts: the cash/bank ledger master (one row per physical account,
 *    plus a "Cash in Hand" cash-type account). Carries an opening balance the
 *    books seed their running total from.
 *  - vouchers: manual entries (Receipt / Payment / Contra / Journal) for money
 *    movements not captured by the transactional modules — capital, drawings,
 *    bank charges, cash<->bank transfers and adjustments.
 *
 * The books themselves are read-only: they union these vouchers with the
 * existing transaction tables (payments, supplier_payments, broker_settlements,
 * tailor_payments, embroidery_payments, expenses). No general ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('type', ['cash', 'bank'])->default('bank');
            $table->string('bank_name')->nullable();
            $table->string('account_number')->nullable();
            $table->string('ifsc')->nullable();
            $table->string('branch_name')->nullable();
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->date('opening_balance_date')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'type']);
        });

        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('voucher_number');
            $table->enum('voucher_type', ['receipt', 'payment', 'contra', 'journal']);
            $table->date('voucher_date');
            $table->decimal('amount', 14, 2);
            $table->enum('mode', ['cash', 'bank', 'upi', 'cheque', 'adjustment'])->nullable();
            // Account affected: receipt = debit/in, payment = credit/out, contra = destination.
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            // Contra source account (money moves counter_account -> bank_account).
            $table->foreignId('counter_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            // Free-text ledger labels for journal vouchers (no chart of accounts).
            $table->string('debit_ledger')->nullable();
            $table->string('credit_ledger')->nullable();
            // Optional linked party (customer/supplier/broker/employee) or free-text name.
            $table->nullableMorphs('party');
            $table->string('party_name')->nullable();
            $table->string('reference_no')->nullable();
            $table->text('narration')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->enum('status', ['posted', 'cancelled'])->default('posted');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'voucher_number']);
            $table->index(['tenant_id', 'voucher_date']);
            $table->index(['tenant_id', 'voucher_type']);
            $table->index(['tenant_id', 'bank_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
        Schema::dropIfExists('bank_accounts');
    }
};
