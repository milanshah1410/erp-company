<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unified payment module. A single voucher records money moving in (customer
 * receipt) or out (supplier/vendor payout, standalone expense), in any mode
 * (cash / bank / upi / cheque / credit / adjustment). Allocations distribute a
 * voucher across one or more open documents (sales / purchase invoices):
 *
 *   - Partial payment  -> an allocation smaller than the document's balance.
 *   - Advance payment  -> a voucher whose amount is not (fully) allocated; the
 *                          unallocated remainder is the party's ledger credit,
 *                          consumed later by allocating it to future invoices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('voucher_number');
            $table->enum('direction', ['inbound', 'outbound']);

            // Polymorphic party (customer / supplier / embroidery vendor / tailor).
            // Null for party-less movements such as standalone expenses.
            $table->string('party_type')->nullable();
            $table->unsignedBigInteger('party_id')->nullable();

            $table->decimal('amount', 15, 2);
            $table->decimal('allocated_amount', 15, 2)->default(0);

            $table->enum('mode', ['cash', 'bank', 'upi', 'cheque', 'credit', 'adjustment'])->default('cash');

            // Instrument details, populated per mode.
            $table->string('reference_no')->nullable();   // bank txn id / UPI ref
            $table->string('bank_name')->nullable();
            $table->string('cheque_no')->nullable();
            $table->date('cheque_date')->nullable();
            $table->enum('cheque_status', ['pending', 'cleared', 'bounced'])->nullable();

            $table->date('payment_date');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'voucher_number']);
            $table->index(['party_type', 'party_id']);
            $table->index(['direction', 'payment_date']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_voucher_id')->constrained('payment_vouchers')->cascadeOnDelete();

            // Polymorphic target document (invoice / purchase_invoice).
            $table->string('allocatable_type');
            $table->unsignedBigInteger('allocatable_id');

            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->index(['allocatable_type', 'allocatable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payment_vouchers');
    }
};
