<?php

declare(strict_types=1);

use App\Models\CentralUser;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-operator flag for saving a tenant's module/menu entitlement.
 *
 * `tenants.edit` still decides who may *open* a workspace's Access panel, but
 * writing it switches features off for a paying customer, so it is narrowed to
 * operators explicitly flagged for it rather than to anyone holding the role.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('central_users', function (Blueprint $table) {
            $table->boolean('can_manage_tenant_access')->default(false)->after('is_active');
        });

        // Backfill the platform's own super-admins: defaulting everyone to false
        // would leave nobody able to save, and no way to grant the flag either.
        $superAdminId = DB::table('roles')
            ->where('guard_name', 'central')
            ->where('name', 'super-admin')
            ->value('id');

        if ($superAdminId) {
            $ids = DB::table('model_has_roles')
                ->where('role_id', $superAdminId)
                ->where('model_type', CentralUser::class)
                ->pluck('model_id');

            if ($ids->isNotEmpty()) {
                DB::table('central_users')->whereIn('id', $ids)->update(['can_manage_tenant_access' => true]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('central_users', function (Blueprint $table) {
            $table->dropColumn('can_manage_tenant_access');
        });
    }
};
