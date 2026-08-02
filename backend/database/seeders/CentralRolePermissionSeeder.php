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

        // White label (Volume 11 §"White Label") — a tenant's logo, custom
        // domains, theme and outgoing-mail account. Its own vocabulary rather
        // than tenants.edit: re-skinning and re-addressing a customer's
        // workspace (and taking over the address their mail is sent from) is a
        // different job from correcting their company profile.
        'white-label' => ['view', 'edit'],

        // Industry templates — the vertical catalogue new signups pick from
        // (Volume 11 §"Industry Templates"). Packaging, so it sits beside plans
        // in practice, but kept its own vocabulary: a billing operator changing
        // prices is not the same person as one redefining what we sell.
        'industries' => ['view', 'create', 'edit', 'delete'],

        // The shared menu catalogue — which screens the product ships at all
        // (/platform/menus). No create/delete: the catalogue is seeded from code
        // alongside the routes it points at, so the only verbs that make sense
        // are "see it" and "switch a screen on or off". Deliberately not folded
        // into `industries`: retiring a screen affects every vertical.
        'menus' => ['view', 'edit'],

        // Marketplace — what the platform offers and to whom (Volume 11
        // §"Integration Marketplace" / §"Device Marketplace"). Split in two
        // because they are different jobs: deciding which payment gateways we
        // resell is commercial, deciding which barcode scanners we support is
        // not. Neither has create/delete — the catalogue ships in code, so the
        // only verbs available are "see it" and "change how it is sold".
        'integrations' => ['view', 'edit'],
        'devices' => ['view', 'edit'],

        // Module marketplace (Volume 11 §"Module Marketplace") — the Industry /
        // Feature / AI packs we sell. Unlike the two catalogues above these ARE
        // database rows, so the full CRUD vocabulary applies. `assign` is separate
        // from `edit` on purpose: composing what a pack contains is product work,
        // handing one to a customer is a commercial act with an invoice attached.
        'packs' => ['view', 'create', 'edit', 'delete', 'assign'],

        // Tenant consumption: API calls, storage, products, customers, AI usage.
        // Read-only by nature — usage is measured, never set.
        'usage' => ['view'],

        // Billing & subscriptions.
        'plans' => ['view', 'create', 'edit', 'delete'],
        'subscriptions' => ['view', 'edit', 'cancel'],
        'invoices' => ['view', 'refund'],
        'payments' => ['view'],
        'revenue' => ['view'],
        // Promotional coupons against our own pricing (Volume 11 §Billing →
        // Coupons) — not the tenant-side retail coupons a shop runs.
        'coupons' => ['view', 'create', 'edit', 'delete'],
        // Renewals & dunning: raising renewal invoices, chasing overdue ones,
        // settling them offline. `manage` is the money-touching half.
        'renewals' => ['view', 'manage'],
        // The platform's own merchant accounts (Volume 11 §Billing → Payment
        // Gateways). `manage` holds live API secrets, so it is deliberately
        // narrow — a billing operator reads it, an owner/devops rotates it.
        'gateways' => ['view', 'manage'],

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

        // Platform Analytics (Volume 11 §"Analytics") — revenue, industry, plan
        // and server-usage cuts across the whole book. Separate from
        // `revenue.view`, which is the billing panel's collections view: this
        // one exposes the customer base itself (who buys what, in which
        // vertical) and reaches into infrastructure, so a billing clerk holding
        // one should not automatically hold the other.
        'analytics' => ['view'],

        // AI Insights (Volume 11 §"AI Insights") — churn prediction, revenue
        // forecast, upsell. Read-only, but it names individual customers as
        // flight risks and price targets, which is a retention/sales tool
        // rather than a reporting one.
        'insights' => ['view'],
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
            || str_starts_with($p, 'api-keys.')
            // Gateway credentials are infrastructure secrets, rotated on the same
            // runbook as any other API key.
            || str_starts_with($p, 'gateways.')));

        return [
            // Full platform control — the SaaS owner.
            'super-admin' => $all,

            // Broad admin, but the truly dangerous levers stay with super-admin:
            // rewriting role permissions and restoring backups.
            'saas-admin' => array_values(array_diff($all, ['roles.edit', 'ops-backups.restore'])),

            'support-executive' => [
                'platform.dashboard.view',
                'tenants.view', 'tenants.impersonate',
                // Read-only on the white label: needed to answer "why does my
                // logo not show", not to re-skin the workspace.
                'white-label.view',
                'support.view',
                'tickets.view', 'tickets.create', 'tickets.edit', 'tickets.close',
                'subscriptions.view', 'invoices.view',
                // "Why is it slow / why can't I upload" is a usage question
                // before it is a bug report.
                'usage.view',
            ],

            'billing-executive' => [
                'platform.dashboard.view',
                'tenants.view',
                // Read-only on the vertical catalogue: needed to price and quote,
                // not to redefine what the product sells.
                'industries.view',
                'plans.view', 'plans.create', 'plans.edit', 'plans.delete',
                'subscriptions.view', 'subscriptions.edit', 'subscriptions.cancel',
                'invoices.view', 'invoices.refund',
                'payments.view', 'revenue.view',
                // Promotions and collections are this role's day job.
                'coupons.view', 'coupons.create', 'coupons.edit', 'coupons.delete',
                'renewals.view', 'renewals.manage',
                // The GST/invoicing profile our tax invoices are raised under.
                'settings.view', 'settings.edit',
                // Reads which gateway is live and in which mode — enough to
                // reconcile a payment. Rotating the API secrets is not billing
                // work, so `gateways.manage` stays with the owner and devops.
                'gateways.view',
                // Sells packs, and prices them: composing what a pack contains is
                // product work, so no packs.create/edit here.
                'packs.view', 'packs.assign',
                // Which plans an integration is bundled into is a pricing
                // decision, so this role owns it outright. Devices are not: the
                // hardware catalogue is an engineering call.
                'integrations.view', 'integrations.edit',
                'devices.view',
                // Usage is what a plan is sold against.
                'usage.view',
            ],

            'sales-executive' => [
                'platform.dashboard.view',
                'tenants.view',
                'industries.view',
                'plans.view',
                'subscriptions.view',
                'marketing.view', 'leads.view',
                // Upsell is this role's whole job, and the upsell list lives on
                // the Insights screen.
                'insights.view',
                // What we can offer a prospect, and what a customer is already
                // consuming — the two halves of an upsell conversation.
                'integrations.view', 'devices.view', 'usage.view',
                // Add-on packs are the upsell; a coupon is how the deal closes.
                'packs.view', 'packs.assign',
                'coupons.view',
            ],

            'customer-success' => [
                'platform.dashboard.view',
                'tenants.view', 'tenants.impersonate',
                // Onboarding a customer's brand is customer-success work, so
                // this role gets the full white-label surface.
                'white-label.view', 'white-label.edit',
                'support.view', 'customer-success.view',
                'tickets.view', 'tickets.create', 'tickets.edit', 'tickets.close',
                'subscriptions.view',
                // Churn prediction is the retention worklist this role runs.
                'insights.view',
                // A workspace against its caps is usually the story behind a
                // "the system is broken" call.
                'usage.view',
            ],

            // Full ops control. The device catalogue is theirs to edit — pulling
            // a brand whose adapter is misbehaving in the field is an incident
            // response, not a pricing change.
            'devops' => array_merge([
                'platform.dashboard.view', 'tenants.view',
                'devices.view', 'devices.edit',
                'integrations.view',
                'usage.view',
            ], $ops),

            // Read-mostly ops — no queue/backup mutation, no flag edits.
            'developer' => [
                'platform.dashboard.view',
                'tenants.view',
                'ops-health.view', 'ops-logs.view', 'ops-queues.view', 'ops-system.view',
                'ops-tools.view',
                'feature-flags.view', 'api-keys.view',
                'integrations.view', 'devices.view', 'usage.view',
            ],

            'finance' => [
                'platform.dashboard.view',
                'tenants.view',
                'plans.view', 'subscriptions.view',
                'invoices.view', 'payments.view', 'revenue.view',
                'reports.view', 'payouts.view',
                // Revenue/plan analytics and the revenue forecast are finance's
                // primary read, so this role gets both surfaces.
                'analytics.view', 'insights.view',
                // Collections and the tax profile: read the ageing, settle what
                // arrives in the bank, and own the GSTIN our invoices carry.
                'renewals.view', 'renewals.manage',
                'coupons.view',
                'settings.view', 'settings.edit',
                'packs.view',
            ],

            'marketing' => [
                'platform.dashboard.view',
                'tenants.view',
                'marketing.view', 'leads.view',
                'announcements.view', 'announcements.create', 'announcements.edit',
                // Which verticals and plans actually sell is the campaign brief.
                'analytics.view',
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
            (string) env('CENTRAL_SUPER_ADMIN_EMAIL', 'owner@kosha.com'),
            (string) env('CENTRAL_SUPER_ADMIN_NAME', 'SaaS Owner'),
            'super-admin',
            (string) env('CENTRAL_SUPER_ADMIN_PASSWORD', 'password'),
        );

        // Billing, Support and Developer — dev-only portal persona logins.
        $this->bootstrapOperator('billing@kosha.com', 'Billing Operator', 'billing-executive');
        $this->bootstrapOperator('support@kosha.com', 'Support Operator', 'support-executive');
        $this->bootstrapOperator('developer@kosha.com', 'Developer Operator', 'developer');
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
