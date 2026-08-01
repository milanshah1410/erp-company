<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill: switch the AI Assistant module on for workspaces provisioned before
 * it joined the common core (App\Support\ShopProfiles — every preset now ticks
 * `ai`).
 *
 * The preset change only reaches new signups: `enabled_modules` is written once,
 * at provisioning, and afterwards only the tenant or the platform operator edits
 * it. Without this, existing workspaces keep an AI-less nav and 403 on every
 * `ai.*` endpoint even though all three plans sell the module.
 *
 * A data migration rather than a seeder because it must run exactly once — a
 * tenant that deliberately unticks AI afterwards should stay untricked.
 *
 * Settings are tenant-scoped rows in the shared schema, and a migration has no
 * tenant context, so this walks the rows directly instead of going through
 * App\Models\Setting (whose global scope would fail closed and match nothing).
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('settings')->where('key', 'enabled_modules')->get(['id', 'value']);

        foreach ($rows as $row) {
            $modules = json_decode((string) $row->value, true);

            // An empty / unparseable selection means gating is off for that
            // tenant (EnabledModules::forTenant fails open), so it already sees
            // AI — writing a one-element list would switch everything else off.
            if (! is_array($modules) || $modules === [] || in_array('ai', $modules, true)) {
                continue;
            }

            $modules[] = 'ai';

            DB::table('settings')
                ->where('id', $row->id)
                ->update(['value' => json_encode(array_values($modules)), 'updated_at' => now()]);
        }
    }

    /**
     * Irreversible on purpose: down() cannot tell a workspace that got `ai` from
     * this backfill apart from one that switched it on itself, and revoking a
     * module a tenant is using is the worse failure.
     */
    public function down(): void
    {
        //
    }
};
