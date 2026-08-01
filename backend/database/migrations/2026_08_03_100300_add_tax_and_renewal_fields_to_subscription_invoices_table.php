<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Central-DB migration: turn a subscription invoice into a GST tax invoice, and
 * give it the fields a renewal/dunning cycle needs (Volume 11 §Billing →
 * Invoices, GST, Renewals).
 *
 * `amount` keeps its meaning — the gross total the tenant pays, the figure every
 * existing revenue query and gateway reconciliation already uses. The new
 * columns decompose it, and the identity the writers maintain is:
 *
 *     taxable_amount = subtotal - discount_amount
 *     tax_amount     = cgst_amount + sgst_amount + igst_amount
 *     amount         = taxable_amount + tax_amount
 *
 * Either CGST+SGST (intra-state) or IGST (inter-state) is populated, never both —
 * see App\Services\Billing\PlatformTaxCalculator, which owns the split.
 *
 * Legacy rows are backfilled as zero-rated with the number the tenant's invoice
 * PDF has been showing all along (`SUB-000123`), so a printed copy keeps
 * matching. New invoices draw a financial-year series number instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table) {
            // Tax-invoice number. Nullable so a draft can exist before issue.
            $table->string('number', 40)->nullable()->unique()->after('id');
            // subscription | pack | adjustment — what was sold.
            $table->string('type', 20)->default('subscription')->index()->after('subscription_id');

            // What the invoice covers, and when payment is expected.
            $table->date('period_start')->nullable()->after('type');
            $table->date('period_end')->nullable()->after('period_start');
            $table->date('due_date')->nullable()->index()->after('period_end');

            // Money decomposition (see the identity in the class docblock).
            $table->decimal('subtotal', 12, 2)->default(0)->after('amount');
            $table->decimal('discount_amount', 12, 2)->default(0)->after('subtotal');
            $table->decimal('taxable_amount', 12, 2)->default(0)->after('discount_amount');
            $table->decimal('tax_rate', 5, 2)->default(0)->after('taxable_amount');
            $table->decimal('cgst_amount', 12, 2)->default(0)->after('tax_rate');
            $table->decimal('sgst_amount', 12, 2)->default(0)->after('cgst_amount');
            $table->decimal('igst_amount', 12, 2)->default(0)->after('sgst_amount');
            $table->decimal('tax_amount', 12, 2)->default(0)->after('igst_amount');

            // GST identity captured at issue time — a later profile edit must not
            // rewrite an invoice already raised.
            $table->string('place_of_supply', 2)->nullable()->after('tax_amount');
            $table->string('seller_state_code', 2)->nullable()->after('place_of_supply');
            $table->boolean('is_interstate')->default(false)->after('seller_state_code');
            $table->string('customer_gstin', 20)->nullable()->after('is_interstate');
            $table->string('customer_legal_name')->nullable()->after('customer_gstin');
            // SAC for the supply (997331 — licensing of computer software).
            $table->string('sac_code', 10)->nullable()->after('customer_legal_name');

            // Coupon applied to this invoice, if any.
            $table->unsignedBigInteger('platform_coupon_id')->nullable()->index()->after('sac_code');
            $table->string('coupon_code', 40)->nullable()->after('platform_coupon_id');

            // The pack this invoice bills, for type = 'pack'.
            $table->unsignedBigInteger('tenant_pack_id')->nullable()->index()->after('coupon_code');

            // Dunning state: collection attempts and reminders already sent, so a
            // daily sweep never emails the same stage twice.
            $table->unsignedTinyInteger('attempt_count')->default(0)->after('paid_at');
            $table->timestamp('last_attempt_at')->nullable()->after('attempt_count');
            $table->unsignedTinyInteger('reminder_stage')->default(0)->after('last_attempt_at');
            $table->timestamp('last_reminder_at')->nullable()->after('reminder_stage');
        });

        // Backfill: existing rows are zero-rated (no GST was charged on them) and
        // keep the number their PDF already displays.
        DB::connection(config('saas.central_connection', 'central'))
            ->table('subscription_invoices')
            ->orderBy('id')
            ->each(function (object $row): void {
                DB::connection(config('saas.central_connection', 'central'))
                    ->table('subscription_invoices')
                    ->where('id', $row->id)
                    ->update([
                        'number' => sprintf('SUB-%06d', $row->id),
                        'subtotal' => $row->amount,
                        'taxable_amount' => $row->amount,
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table) {
            $table->dropUnique(['number']);
            $table->dropIndex(['type']);
            $table->dropIndex(['due_date']);
            $table->dropIndex(['platform_coupon_id']);
            $table->dropIndex(['tenant_pack_id']);
            $table->dropColumn([
                'number', 'type', 'period_start', 'period_end', 'due_date',
                'subtotal', 'discount_amount', 'taxable_amount', 'tax_rate',
                'cgst_amount', 'sgst_amount', 'igst_amount', 'tax_amount',
                'place_of_supply', 'seller_state_code', 'is_interstate',
                'customer_gstin', 'customer_legal_name', 'sac_code',
                'platform_coupon_id', 'coupon_code', 'tenant_pack_id',
                'attempt_count', 'last_attempt_at', 'reminder_stage', 'last_reminder_at',
            ]);
        });
    }
};
