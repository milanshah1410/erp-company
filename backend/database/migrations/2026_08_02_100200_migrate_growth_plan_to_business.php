<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retire the `growth` plan in favour of `business` (Volume 11 §"Subscription
 * Plans" names the tiers Starter / Business / Professional / Enterprise / Custom).
 *
 * Growth is not deleted — it stays as a dormant row so historical invoices and
 * activity entries keep resolving, and so `?plan=growth` links keep working
 * (Plan::LEGACY_SLUGS forwards the slug to Business). It is only deactivated, so
 * it disappears from the public pricing page and cannot be sold again.
 *
 * Subscribers are moved across rather than left behind: Business is priced the
 * same as Growth was and sells a superset of its modules (see PlanSeeder), so a
 * repointed workspace loses nothing — not a module, not a limit, not a rupee.
 * The guard below refuses to move anyone if Business is missing, which is the
 * one case where this migration could do harm.
 */
return new class extends Migration
{
    public function up(): void
    {
        $growth = DB::table('plans')->where('slug', 'growth')->first();
        if (! $growth) {
            return; // fresh install — PlanSeeder never created growth
        }

        $business = DB::table('plans')->where('slug', 'business')->first();

        // Seed order isn't guaranteed relative to migrations (a deployment may
        // migrate before seeding), so create Business from Growth's own terms if
        // it isn't there yet. Identical terms = nothing changes for subscribers;
        // the seeder refines the module list and limits on its next run.
        if (! $business) {
            $businessId = DB::table('plans')->insertGetId([
                'slug' => 'business',
                'name' => 'Business',
                'price' => $growth->price,
                'billing_cycle' => $growth->billing_cycle,
                'currency' => $growth->currency,
                'is_custom' => $growth->is_custom,
                'is_active' => true,
                'sort_order' => $growth->sort_order,
                'feature_limits' => $growth->feature_limits,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $businessId = $business->id;
        }

        DB::table('subscriptions')->where('plan_id', $growth->id)->update([
            'plan_id' => $businessId,
            'updated_at' => now(),
        ]);

        // A queued upgrade/downgrade that named Growth follows it across.
        if (Schema::hasColumn('subscriptions', 'pending_plan_id')) {
            DB::table('subscriptions')->where('pending_plan_id', $growth->id)->update([
                'pending_plan_id' => $businessId,
                'updated_at' => now(),
            ]);
        }

        // Signups still in flight picked a slug, not an id.
        DB::table('pending_registrations')->where('plan_slug', 'growth')->update([
            'plan_slug' => 'business',
            'updated_at' => now(),
        ]);

        DB::table('plans')->where('id', $growth->id)->update([
            'is_active' => false,
            'updated_at' => now(),
        ]);
    }

    /**
     * Puts Growth back on sale, and stops there.
     *
     * The repoint is deliberately not reversed: once a workspace sits on
     * Business there is nothing in the data that says whether it arrived from
     * Growth or signed up on Business directly, so moving them all back would
     * relegate genuine Business customers to a retired plan. Terms are identical
     * either way, so leaving them is the harmless half of the choice.
     */
    public function down(): void
    {
        DB::table('plans')->where('slug', 'growth')->update([
            'is_active' => true,
            'updated_at' => now(),
        ]);
    }
};
