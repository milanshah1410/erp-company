<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central-DB migration: the module marketplace (Volume 11 §Module Marketplace →
 * Industry Packs, Feature Packs, AI Packs).
 *
 * A pack is a sellable bundle of entitlement, and entitlement in this product has
 * exactly two currencies: which modules a workspace may switch on, and how high
 * its metered caps sit. So a pack carries `modules` and `limit_bumps` and nothing
 * else — granting one flows through the machinery that already gates the nav, the
 * role matrix and the API (App\Services\Billing\PackEntitlementService writes the
 * `pack_modules` setting that App\Support\EnabledModules unions in). There is no
 * second enforcement path to keep in sync.
 *
 * `included_in_plans` is how a pack stops being an add-on: list a plan slug and
 * every subscriber on that tier gets the pack's entitlement without a purchase,
 * which is what makes "Professional includes the E-commerce pack" expressible
 * without duplicating module lists into the plan catalogue.
 *
 * A `tenant_packs` row is the grant itself — bought, comped or plan-bundled — and
 * is the thing an operator revokes. Rows are kept after cancellation as billing
 * history, so uniqueness is on the live grant only and enforced in the service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_packs', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('name');
            // industry | feature | ai — the three shelves the marketplace shows.
            $table->string('type', 12)->default('feature')->index();
            $table->string('tagline')->nullable();
            $table->text('description')->nullable();
            $table->string('icon', 60)->nullable();

            $table->decimal('price', 12, 2)->default(0);
            // monthly | yearly | one_time. one_time grants perpetually.
            $table->string('billing_cycle', 10)->default('monthly');
            $table->string('currency', 3)->default('INR');

            // Module keys the pack unlocks (App\Support\Modules::keys()).
            $table->json('modules')->nullable();
            // Additive bumps to plan feature limits: {"max_users": 5}
            $table->json('limit_bumps')->nullable();
            // Industry-template key this pack belongs to, for type = 'industry'.
            $table->string('industry_key', 50)->nullable()->index();
            // Plan slugs that bundle this pack at no extra charge.
            $table->json('included_in_plans')->nullable();

            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('tenant_packs', function (Blueprint $table) {
            $table->id();
            // Landlord id, not an FK: a purged tenant leaves its billing history.
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('platform_pack_id')->constrained('platform_packs')->cascadeOnDelete();

            $table->string('status', 12)->default('active')->index(); // active | cancelled | expired
            // purchased (billed) | comped (free, operator decision) | bundled (plan)
            $table->string('source', 12)->default('purchased');

            $table->decimal('price', 12, 2)->default(0);
            $table->string('currency', 3)->default('INR');
            $table->date('starts_at')->nullable();
            // null = runs until cancelled (or perpetual, for a one-time purchase).
            $table->date('ends_at')->nullable()->index();

            $table->unsignedBigInteger('subscription_invoice_id')->nullable()->index();
            $table->unsignedBigInteger('granted_by')->nullable(); // central_users.id
            $table->timestamp('cancelled_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_packs');
        Schema::dropIfExists('platform_packs');
    }
};
