<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the SaaS operator record offline renewals (bank transfer, UPI, cheque,
 * cash) as real paid invoices instead of just force-editing the subscription
 * status. `payment_method` is null for gateway (Razorpay) charges.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table) {
            // bank_transfer | upi | cheque | cash | other — null = gateway
            $table->string('payment_method')->nullable()->after('payment_gateway_ref');
            $table->string('notes')->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'notes']);
        });
    }
};
