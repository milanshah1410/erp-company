<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Volume 11 §"Tenant Management": Industry, Business Type and Limits.
 *
 * Industry and trade were already asked at signup, but only ever landed in the
 * tenant's own `settings` rows — data the platform panel cannot list, filter or
 * report on without opening each workspace. Mirroring them onto the landlord
 * `tenants` row makes them first-class tenant attributes: the tenant list can
 * group by industry, and Analytics §"Industry" has something to aggregate.
 *
 * `limit_overrides` is the per-tenant escape hatch on plan feature limits — a
 * comped seat count for one customer, without inventing a bespoke plan for
 * them. Null (or a missing key) means "whatever the plan says"; see
 * App\Support\TenantLimits, which is the only reader.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Industry-template key (IndustryTemplate::key) — 'retail', 'restaurant', …
            $table->string('industry', 50)->nullable()->index()->after('company_name');
            // Trade / shop-profile key within that industry — 'grocery', 'bakery', …
            // Same key space as the `shop_profile` tenant setting.
            $table->string('business_type', 50)->nullable()->index()->after('industry');
            // Per-tenant overrides of plan feature limits: {"max_users": 25}
            $table->json('limit_overrides')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['industry']);
            $table->dropIndex(['business_type']);
            $table->dropColumn(['industry', 'business_type', 'limit_overrides']);
        });
    }
};
