<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Central-DB migration: refund bookkeeping for SaaS billing invoices. A refund
// flips status to 'refunded' and records how much / why / when here — the
// money itself moves via the gateway dashboard or bank, out of band.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table) {
            $table->decimal('refund_amount', 12, 2)->nullable()->after('paid_at');
            $table->string('refund_reason')->nullable()->after('refund_amount');
            $table->timestamp('refunded_at')->nullable()->after('refund_reason');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table) {
            $table->dropColumn(['refund_amount', 'refund_reason', 'refunded_at']);
        });
    }
};
