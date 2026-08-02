<?php

declare(strict_types=1);

use App\Support\Modules;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill: switch the Core ERP set (App\Support\Modules::CORE) on for every
 * workspace provisioned before it became every tenant's floor.
 *
 * Strictly speaking this is belt and braces — EnabledModules::forTenant() unions
 * core in on every read, so a stale stored list already behaves correctly. It is
 * still worth writing, because `enabled_modules` is read directly by two screens
 * that do not go through that resolver (the platform Access panel and the
 * marketplace pack projection), and a stored value that disagrees with the
 * effective one reads as a bug every time someone opens it.
 *
 * Runs on the tenant connection's `settings` table with raw queries: Setting's
 * tenant scope fails closed with no tenant context, which a migration never has.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('settings')->where('key', 'enabled_modules')->get(['id', 'value']);

        foreach ($rows as $row) {
            $modules = json_decode((string) $row->value, true);

            // An empty or unparseable selection means gating already fails open
            // for that tenant, so it sees core and everything else; writing a
            // six-element list here would switch the rest off.
            if (! is_array($modules) || $modules === []) {
                continue;
            }

            if (array_diff(Modules::CORE, $modules) === []) {
                continue;
            }

            $merged = Modules::withCore($modules);

            DB::table('settings')
                ->where('id', $row->id)
                ->update(['value' => json_encode($merged), 'updated_at' => now()]);
        }
    }

    /** Irreversible: cannot tell a backfilled tenant from one that opted in. */
    public function down(): void
    {
        //
    }
};
