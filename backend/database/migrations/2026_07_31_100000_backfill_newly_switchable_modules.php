<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Loyalty & Offers, Assets, Workflow Automation, Device Manager and AI used to
 * be untagged menu rows — always visible, whatever a tenant switched on. They
 * are proper modules now, which means a workspace that already stored an
 * `enabled_modules` set (every tenant provisioned through the wizard) would
 * suddenly stop seeing screens it has been using.
 *
 * So append them once, to existing selections only. New signups pick them from
 * the shop-type preset like any other module; this backfill exists purely so
 * making something switchable never reads as taking it away.
 *
 * Written with the query builder, so the per-tenant settings cache is not
 * invalidated by a model event. It carries a short TTL and heals itself; a
 * running app may show the old nav for a few minutes after deploy.
 */
return new class extends Migration
{
    /** The keys that changed from "always on" to "switchable" in this release. */
    private const NEWLY_SWITCHABLE = ['loyalty', 'assets', 'workflow', 'devices', 'ai'];

    public function up(): void
    {
        // The plan is a ceiling applied on every read (EnabledModules::forTenant),
        // so widening the tenant selections alone would achieve nothing — a
        // Starter plan whose feature_limits still list the old five keys would
        // narrow them straight back out. Patch the stored plans too, so this
        // works on a deployed database without re-running PlanSeeder.
        $this->widenPlanCeilings();

        $rows = DB::table('settings')
            ->where('key', 'enabled_modules')
            ->get(['id', 'value']);

        foreach ($rows as $row) {
            $modules = json_decode((string) $row->value, true);

            // A malformed or empty selection means gating already fails open for
            // this tenant (EnabledModules::forTenant returns null) — leave it be.
            if (! is_array($modules) || $modules === []) {
                continue;
            }

            $merged = array_values(array_unique(array_merge($modules, self::NEWLY_SWITCHABLE)));

            if (count($merged) === count($modules)) {
                continue;
            }

            DB::table('settings')
                ->where('id', $row->id)
                ->update(['value' => json_encode($merged), 'updated_at' => now()]);
        }
    }

    /**
     * Append the newly switchable keys to every plan that lists an explicit
     * module set. Plans on '*' (Enterprise) already allow everything and are
     * left alone.
     */
    private function widenPlanCeilings(): void
    {
        foreach (DB::table('plans')->get(['id', 'feature_limits']) as $plan) {
            $limits = json_decode((string) $plan->feature_limits, true);

            if (! is_array($limits)) {
                continue;
            }

            $modules = $limits['modules'] ?? null;

            // Absent or wildcard: nothing to widen — both already mean "all".
            if (! is_array($modules) || in_array('*', $modules, true)) {
                continue;
            }

            $merged = array_values(array_unique(array_merge($modules, self::NEWLY_SWITCHABLE)));

            if (count($merged) === count($modules)) {
                continue;
            }

            $limits['modules'] = $merged;

            DB::table('plans')
                ->where('id', $plan->id)
                ->update(['feature_limits' => json_encode($limits), 'updated_at' => now()]);
        }
    }

    /**
     * Strip the appended keys back out. Lossy by nature: a tenant that switched
     * one of these off after the backfill, or on for the first time, is not
     * distinguishable from one this migration touched.
     */
    public function down(): void
    {
        $rows = DB::table('settings')
            ->where('key', 'enabled_modules')
            ->get(['id', 'value']);

        foreach ($rows as $row) {
            $modules = json_decode((string) $row->value, true);

            if (! is_array($modules) || $modules === []) {
                continue;
            }

            $stripped = array_values(array_diff($modules, self::NEWLY_SWITCHABLE));

            if (count($stripped) === count($modules)) {
                continue;
            }

            DB::table('settings')
                ->where('id', $row->id)
                ->update(['value' => json_encode($stripped), 'updated_at' => now()]);
        }
    }
};
