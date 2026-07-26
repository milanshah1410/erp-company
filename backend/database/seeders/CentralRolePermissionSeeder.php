<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CentralUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * SaaS-owner RBAC — Level 1 (project-stack.md §"Enterprise RBAC").
 *
 * These are the platform operators who run the SaaS itself — NOT tenant users.
 * All records live on the `central` guard with team_id = NULL so they never
 * collide with the tenant `web` roles that share the same Spatie tables.
 *
 * The permission vocabulary covers the four control surfaces the platform panel
 * exposes: Tenant management, Billing & subscriptions, Support & Customer
 * Success, and Ops (DevOps/Developer) — plus platform administration itself.
 */
class CentralRolePermissionSeeder extends Seeder
{
    private const GUARD = 'central';

    /**
     * The full platform permission vocabulary, grouped by control surface.
     * Flattened into individual "module.action" permissions below.
     *
     * @var array<string, array<int, string>>
     */
    private array $groups = [
        // Platform administration (operators, roles, audit, platform settings).
        'platform' => ['dashboard.view'],
        'operators' => ['view', 'create', 'edit', 'delete'],
        'roles' => ['view', 'edit'],
        'audit' => ['view'],
        'settings' => ['view', 'edit'],

        // Tenant management.
        'tenants' => ['view', 'create', 'edit', 'suspend', 'delete', 'impersonate'],

        // Billing & subscriptions.
        'plans' => ['view', 'create', 'edit', 'delete'],
        'subscriptions' => ['view', 'edit', 'cancel'],
        'invoices' => ['view', 'refund'],
        'payments' => ['view'],
        'revenue' => ['view'],

        // Support & Customer Success.
        'support' => ['view'],
        'tickets' => ['view', 'create', 'edit', 'close'],
        'customer-success' => ['view'],

        // Ops — DevOps / Developer.
        'ops-health' => ['view'],
        'ops-queues' => ['view', 'manage'],
        'ops-backups' => ['view', 'create', 'restore'],
        'ops-logs' => ['view'],
        'ops-system' => ['view'],
        // Tenant tools: performance diagnostics (view) + demo-data generation
        // into a tenant (manage). Moved off the tenant's System Tools screen.
        'ops-tools' => ['view', 'manage'],
        'feature-flags' => ['view', 'edit'],
        'api-keys' => ['view', 'manage'],

        // Marketing.
        'marketing' => ['view'],
        'announcements' => ['view', 'create', 'edit'],
        'leads' => ['view'],

        // Finance (mostly read across the money surfaces + platform reporting).
        'reports' => ['view'],
        'payouts' => ['view'],
    ];

    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        // Central operators are not tenant-scoped: everything lives under the
        // reserved sentinel team (the pivot's team key can't be NULL).
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId(CentralUser::TEAM_ID);

        try {
            $all = $this->buildPermissions();

            // Ensure every permission exists via a DB-level firstOrCreate rather
            // than Permission::findOrCreate(): the latter resolves through
            // Spatie's cached permission collection, which can be stale on a
            // re-run (or mid-seed) and then attempt to INSERT a name that already
            // exists — a unique-constraint crash. firstOrCreate always hits the
            // database, so the seeder is safely idempotent. (Permissions carry no
            // team column, so name + guard is the whole identity.)
            foreach ($all as $name) {
                Permission::query()->firstOrCreate([
                    'name' => $name,
                    'guard_name' => self::GUARD,
                ]);
            }

            // Rebuild the cache so the role sync below resolves every permission
            // just ensured, not a snapshot taken before this run.
            $registrar->forgetCachedPermissions();

            foreach ($this->roleMap($all) as $roleName => $perms) {
                $role = Role::findOrCreate($roleName, self::GUARD);
                $role->syncPermissions($perms);
            }

            $this->bootstrapOperators();
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
            $registrar->forgetCachedPermissions();
        }
    }

    /**
     * Flatten the grouped vocabulary into "module.action" permission names.
     *
     * @return array<int, string>
     */
    private function buildPermissions(): array
    {
        $permissions = [];
        foreach ($this->groups as $module => $actions) {
            foreach ($actions as $action) {
                $permissions[] = "{$module}.{$action}";
            }
        }

        return $permissions;
    }

    /**
     * Map each of the 11 Level-1 roles to its permission set.
     *
     * @param  array<int, string>  $all  every platform permission
     * @return array<string, array<int, string>>
     */
    private function roleMap(array $all): array
    {
        $viewOnly = array_values(array_filter($all, fn ($p) => str_ends_with($p, '.view')));

        $ops = array_values(array_filter($all, fn ($p) => str_starts_with($p, 'ops-')
            || str_starts_with($p, 'feature-flags.')
            || str_starts_with($p, 'api-keys.')));

        return [
            // Full platform control — the SaaS owner.
            'super-admin' => $all,

            // Broad admin, but the truly dangerous levers stay with super-admin:
            // rewriting role permissions and restoring backups.
            'saas-admin' => array_values(array_diff($all, ['roles.edit', 'ops-backups.restore'])),

            'support-executive' => [
                'platform.dashboard.view',
                'tenants.view', 'tenants.impersonate',
                'support.view',
                'tickets.view', 'tickets.create', 'tickets.edit', 'tickets.close',
                'subscriptions.view', 'invoices.view',
            ],

            'billing-executive' => [
                'platform.dashboard.view',
                'tenants.view',
                'plans.view', 'plans.create', 'plans.edit', 'plans.delete',
                'subscriptions.view', 'subscriptions.edit', 'subscriptions.cancel',
                'invoices.view', 'invoices.refund',
                'payments.view', 'revenue.view',
            ],

            'sales-executive' => [
                'platform.dashboard.view',
                'tenants.view',
                'plans.view',
                'subscriptions.view',
                'marketing.view', 'leads.view',
            ],

            'customer-success' => [
                'platform.dashboard.view',
                'tenants.view', 'tenants.impersonate',
                'support.view', 'customer-success.view',
                'tickets.view', 'tickets.create', 'tickets.edit', 'tickets.close',
                'subscriptions.view',
            ],

            // Full ops control.
            'devops' => array_merge(['platform.dashboard.view', 'tenants.view'], $ops),

            // Read-mostly ops — no queue/backup mutation, no flag edits.
            'developer' => [
                'platform.dashboard.view',
                'tenants.view',
                'ops-health.view', 'ops-logs.view', 'ops-queues.view', 'ops-system.view',
                'ops-tools.view',
                'feature-flags.view', 'api-keys.view',
            ],

            'finance' => [
                'platform.dashboard.view',
                'tenants.view',
                'plans.view', 'subscriptions.view',
                'invoices.view', 'payments.view', 'revenue.view',
                'reports.view', 'payouts.view',
            ],

            'marketing' => [
                'platform.dashboard.view',
                'tenants.view',
                'marketing.view', 'leads.view',
                'announcements.view', 'announcements.create', 'announcements.edit',
            ],

            // Read-only everything, plus the audit trail.
            'auditor' => array_values(array_unique(array_merge($viewOnly, ['audit.view']))),
        ];
    }

    /**
     * Seed a default login account for each SaaS Owner Portal persona so the
     * platform panel (admin.yourerp.com) is usable on a fresh install:
     * Super Admin, Billing, Support and Developer. These operators are not
     * tenant users — they run the SaaS itself and can see every tenant.
     *
     * The Super Admin credentials come from env; every default here is dev-only
     * and should be changed/disabled in production.
     */
    private function bootstrapOperators(): void
    {
        // Super Admin — the primary owner login, env-overridable.
        $this->bootstrapOperator(
            (string) env('CENTRAL_SUPER_ADMIN_EMAIL', 'owner@saas.test'),
            (string) env('CENTRAL_SUPER_ADMIN_NAME', 'SaaS Owner'),
            'super-admin',
            (string) env('CENTRAL_SUPER_ADMIN_PASSWORD', 'password'),
        );

        // Billing, Support and Developer — dev-only portal persona logins.
        $this->bootstrapOperator('billing@saas.test', 'Billing Operator', 'billing-executive');
        $this->bootstrapOperator('support@saas.test', 'Support Operator', 'support-executive');
        $this->bootstrapOperator('developer@saas.test', 'Developer Operator', 'developer');
    }

    /**
     * Idempotently create/refresh a single platform operator and pin it to its
     * role. Existing accounts keep their current password (never reset on
     * re-seed); only brand-new accounts get the supplied default.
     */
    private function bootstrapOperator(string $email, string $name, string $role, string $password = 'password'): void
    {
        $operator = CentralUser::firstOrNew(['email' => $email]);
        $operator->name = $operator->name ?: $name;
        $operator->is_active = true;
        if (! $operator->exists) {
            $operator->password = Hash::make($password);
        }
        $operator->save();

        $operator->syncRoles([$role]);
    }
}
