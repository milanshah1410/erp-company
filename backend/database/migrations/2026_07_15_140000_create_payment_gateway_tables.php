<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Online payment collection (SRS §16). Two tables:
 *
 *  - payment_gateway_accounts: a tenant's configured gateway (Razorpay,
 *    Cashfree, PhonePe, PayU, CCAvenue, Stripe) with its (encrypted) API
 *    credentials and test/live mode. One may be flagged default per tenant.
 *
 *  - payment_transactions: one row per hosted payment link / attempt against a
 *    sales invoice. Carries the public token used by the customer pay page, the
 *    gateway's order/payment ids for reconciliation, and — once paid — a link
 *    to the PaymentVoucher the receipt was booked as.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateway_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('gateway');                       // razorpay|cashfree|phonepe|payu|ccavenue|stripe
            $table->string('label')->nullable();             // operator-facing name
            $table->enum('mode', ['test', 'live'])->default('test');
            $table->text('credentials')->nullable();         // encrypted JSON (key/secret/...)
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'gateway']);
        });

        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('payment_gateway_account_id')->nullable()->constrained('payment_gateway_accounts')->nullOnDelete();
            $table->foreignId('payment_voucher_id')->nullable()->constrained('payment_vouchers')->nullOnDelete();

            $table->string('gateway')->nullable();           // resolved at checkout start
            $table->string('public_token', 64)->unique();    // pay-page identifier
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('INR');

            // created -> pending -> paid | failed | expired | cancelled
            $table->enum('status', ['created', 'pending', 'paid', 'failed', 'expired', 'cancelled'])->default('created');

            $table->string('gateway_order_id')->nullable();  // gateway's order/link id
            $table->string('gateway_payment_id')->nullable();// gateway's payment/txn id
            $table->string('checkout_url')->nullable();      // hosted checkout (redirect flows)
            $table->text('meta')->nullable();                // JSON: gateway payloads / notes
            $table->string('failure_reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index('gateway_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('payment_gateway_accounts');
    }
};
