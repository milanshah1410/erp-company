<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central-DB migration: promotional coupons the SaaS operator issues against its
 * own subscription pricing (Volume 11 §Billing → Coupons).
 *
 * Deliberately NOT the tenant-side `coupons` table: that one is a retail
 * discount a shop offers its own customers at the POS counter. These reduce what
 * a tenant pays the platform, so they live on the landlord side and are priced
 * in the plan's currency.
 *
 * `duration` follows the shape every subscription biller converges on:
 *   once      — the next invoice only (a launch discount)
 *   repeating — the next `duration_cycles` invoices (3 months at 50% off)
 *   forever   — every invoice while the subscription lives (a legacy price)
 * A repeating/forever coupon is pinned onto the subscription so renewals keep
 * applying it; `once` is consumed by the invoice that used it.
 *
 * Redemptions are recorded rather than counted on the fly: `redemption_count` is
 * the enforcement counter (checked under a row lock) and the redemptions table
 * is the audit trail behind it, including which invoice the discount landed on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_coupons', function (Blueprint $table) {
            $table->id();
            // Stored upper-cased; matching is done on the normalised code.
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('description', 500)->nullable();

            // percent → value is 0-100; flat → value is money in `currency`.
            $table->string('discount_type', 10)->default('percent');
            $table->decimal('discount_value', 12, 2);
            $table->string('currency', 3)->default('INR');
            // Caps a percent discount in money terms ("20% off, up to ₹2,000").
            $table->decimal('max_discount_amount', 12, 2)->nullable();
            // Floor: the invoice must be at least this much (pre-tax) to qualify.
            $table->decimal('min_amount', 12, 2)->nullable();

            $table->string('duration', 12)->default('once'); // once|repeating|forever
            $table->unsignedSmallInteger('duration_cycles')->nullable();

            // What the coupon may be spent on: plan subscriptions, marketplace
            // packs, or either.
            $table->string('applies_to', 12)->default('subscription'); // subscription|pack|both
            // Plan ids the coupon is restricted to; null/[] = every plan.
            $table->json('plan_ids')->nullable();
            // Only redeemable by a workspace that has never paid before.
            $table->boolean('new_tenants_only')->default(false);

            $table->unsignedInteger('max_redemptions')->nullable();       // total, across tenants
            $table->unsignedSmallInteger('max_redemptions_per_tenant')->default(1);
            $table->unsignedInteger('redemption_count')->default(0);

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true)->index();

            $table->unsignedBigInteger('created_by')->nullable(); // central_users.id
            $table->timestamps();
        });

        Schema::create('platform_coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_coupon_id')->constrained('platform_coupons')->cascadeOnDelete();
            // Landlord ids, not FKs: a tenant/subscription may be purged while the
            // redemption stays on the books for revenue reporting.
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('subscription_id')->nullable()->index();
            $table->unsignedBigInteger('subscription_invoice_id')->nullable()->index();
            // Snapshot of the code as redeemed — the coupon row may be renamed.
            $table->string('code', 40);
            $table->decimal('discount_amount', 12, 2);
            $table->string('currency', 3)->default('INR');
            $table->timestamp('redeemed_at');
            $table->timestamps();

            $table->index(['platform_coupon_id', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_coupon_redemptions');
        Schema::dropIfExists('platform_coupons');
    }
};
