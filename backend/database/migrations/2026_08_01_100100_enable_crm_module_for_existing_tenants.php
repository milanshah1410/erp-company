<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill: switch CRM on for workspaces provisioned before it joined the common
 * core (App\Support\ShopProfiles — every preset now ticks `crm`). Sibling of
 * 2026_08_01_100000_enable_ai_module_for_existing_tenants; see that file for why
 * a preset edit alone cannot reach an existing tenant.
 *
 * Harmless for a Starter workspace, which does not buy CRM: the stored selection
 * is narrowed to the plan's ceiling on every read, so the row simply sits there
 * until an upgrade makes it count.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('settings')->where('key', 'enabled_modules')->get(['id', 'value']);

        foreach ($rows as $row) {
            $modules = json_decode((string) $row->value, true);

            // An empty / unparseable selection means gating already fails open
            // for that tenant, so it sees CRM; writing a one-element list would
            // switch everything else off.
            if (! is_array($modules) || $modules === [] || in_array('crm', $modules, true)) {
                continue;
            }

            $modules[] = 'crm';

            DB::table('settings')
                ->where('id', $row->id)
                ->update(['value' => json_encode(array_values($modules)), 'updated_at' => now()]);
        }
    }

    /** Irreversible: cannot tell a backfilled tenant from one that opted in. */
    public function down(): void
    {
        //
    }
};
