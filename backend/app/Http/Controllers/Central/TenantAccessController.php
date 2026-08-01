<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\Modules;
use App\Support\PackEntitlement;
use App\Support\TenantCache;
use App\Support\TenantContext;
use App\Support\TenantMenuAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Per-tenant access control for the SaaS-owner panel (/platform/tenants → Access).
 *
 * Two levels, one screen:
 *   modules — writes the tenant's `enabled_modules` setting, the coarse switch
 *             the onboarding wizard sets and the plan caps;
 *   menu    — flips `is_active` on the tenant's own menu rows, so a single screen
 *             can be revoked while its module stays on.
 *
 * Revoking either one is a real entitlement, not a cosmetic hide: it also removes
 * the permissions behind the screen from the tenant's role matrix and closes the
 * endpoint (App\Support\TenantMenuAccess documents the three enforcement points).
 * Existing role grants are left in the database, so re-enabling a screen restores
 * exactly what the tenant had.
 *
 * Both endpoints are gated by the operator's own `can_manage_tenant_access` flag
 * (App\Http\Middleware\EnsureTenantAccessManager) rather than by a role
 * permission: this is granted operator by operator, and super-admins — the only
 * ones who may hand the flag out — hold it implicitly.
 */
class TenantAccessController extends Controller
{
    /**
     * The tenant's current entitlement: module switches (with what its plan
     * allows) and the full menu tree with each row's on/off state.
     */
    public function show(Tenant $tenant): JsonResponse
    {
        $plan = Subscription::with('plan')->where('tenant_id', $tenant->id)->latest('id')->first()?->plan;
        $allowedByPlan = $plan?->allowedModules() ?? Modules::keys();

        // Modules the workspace holds through a marketplace pack. Shown so an
        // operator can see why a module beyond the plan is on — and so unticking
        // it reads as "this needs the pack revoked", not as a lever that failed.
        $packModules = PackEntitlement::modulesFor($tenant->id);

        return app(TenantContext::class)->runWith($tenant->id, function () use ($tenant, $plan, $allowedByPlan, $packModules): JsonResponse {
            // A legacy tenant (provisioned before per-tenant menus) has no rows of
            // its own and is served the global catalogue. Clone it now so the
            // operator has something tenant-owned to switch off.
            TenantMenuAccess::cloneForTenant($tenant->id);

            $stored = $this->storedModules();
            // No stored selection means gating is off for this tenant — every
            // module reads as on, which is what it actually gets today.
            $enabled = $stored ?? $allowedByPlan;

            $rows = MenuItem::where('tenant_id', $tenant->id)
                ->orderBy('sort_order')
                ->get(['id', 'parent_id', 'label', 'icon', 'route', 'permission', 'module', 'is_active']);

            return response()->json([
                'tenant' => [
                    'id' => $tenant->id,
                    'company_name' => $tenant->company_name,
                    'plan' => $plan?->name,
                ],
                'modules' => collect(Modules::CATALOGUE)->map(fn (array $meta, string $key) => [
                    'key' => $key,
                    'label' => $meta['label'],
                    'icon' => $meta['icon'],
                    'description' => $meta['description'],
                    'enabled' => in_array($key, $enabled, true),
                    // Whether the plan sells it — advisory, not a lock: the
                    // operator may switch on anything and the workspace keeps it
                    // (the grant is recorded, see update()).
                    'allowed_by_plan' => in_array($key, $allowedByPlan, true),
                    // Held through a marketplace pack. Switching it off here only
                    // hides the menu; the entitlement returns on the next sync
                    // until the pack itself is revoked.
                    'granted_by_pack' => in_array($key, $packModules, true),
                ])->values(),
                // False when the tenant never chose a module set; the UI says so,
                // because saving this screen is what starts gating it.
                'modules_gated' => $stored !== null,
                'menu' => $rows->map(fn (MenuItem $row) => [
                    'id' => $row->id,
                    'parent_id' => $row->parent_id,
                    'label' => $row->label,
                    'icon' => $row->icon,
                    'route' => $row->route,
                    'permission' => $row->permission,
                    'module' => $row->module,
                    'is_active' => (bool) $row->is_active,
                ])->values(),
                'revoked_groups' => TenantMenuAccess::revokedGroups($tenant->id),
            ]);
        });
    }

    /**
     * Save the tenant's entitlement.
     *
     * `modules` is the full enabled set (not a delta) and `disabled_menu_ids` the
     * rows to switch off — everything else is switched back on, so the payload
     * describes the desired end state and re-sending it is idempotent.
     */
    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'modules' => ['present', 'array'],
            'modules.*' => ['string'],
            'disabled_menu_ids' => ['present', 'array'],
            'disabled_menu_ids.*' => ['integer'],
        ]);

        $plan = Subscription::with('plan')->where('tenant_id', $tenant->id)->latest('id')->first()?->plan;
        $allowedByPlan = $plan?->allowedModules() ?? Modules::keys();

        // Unknown keys are dropped, but the plan is NOT a cap here: the operator
        // is the SaaS owner and may comp a workspace a module it never bought.
        // Anything switched on beyond the plan is recorded as a grant, which is
        // what stops EnabledModules narrowing it away again on the next read.
        //
        // Pack-held modules are excluded from that record: the pack already
        // entitles them, and writing them here too would leave the module switched
        // on as an operator comp after the pack was revoked.
        $modules = Modules::sanitize($data['modules']);
        $packModules = PackEntitlement::modulesFor($tenant->id);
        $grants = array_values(array_diff($modules, $allowedByPlan, $packModules));

        return app(TenantContext::class)->runWith($tenant->id, function () use ($tenant, $modules, $grants, $data): JsonResponse {
            TenantMenuAccess::cloneForTenant($tenant->id);

            $disabled = array_map('intval', $data['disabled_menu_ids']);

            DB::transaction(function () use ($tenant, $modules, $grants, $disabled): void {
                // Stored as a JSON string, the same shape provisioning writes —
                // the `value` column is plain text with no cast behind it.
                Setting::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'key' => 'enabled_modules'],
                    ['value' => json_encode($modules)],
                );

                Setting::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'key' => 'module_grants'],
                    ['value' => json_encode($grants)],
                );

                // Two mass updates rather than a row-per-save loop. They fire no
                // model events, so the cache version is bumped by hand below.
                $rows = MenuItem::where('tenant_id', $tenant->id);
                (clone $rows)->whereIn('id', $disabled)->update(['is_active' => false]);
                (clone $rows)->whereNotIn('id', $disabled === [] ? [0] : $disabled)->update(['is_active' => true]);
            });

            // Bumping 'menu_items' invalidates both the cached nav catalogue and
            // this tenant's revoked-group set in one go.
            TenantCache::bump('menu_items');

            return $this->show($tenant);
        });
    }

    /**
     * The tenant's stored module selection, or null when it never made one.
     *
     * Read through the raw table: Setting's accessor caching is keyed per tenant
     * and we want the value as written, not a possibly-cached map.
     *
     * @return list<string>|null
     */
    private function storedModules(): ?array
    {
        $raw = Setting::where('key', 'enabled_modules')->value('value');
        if (empty($raw)) {
            return null;
        }

        $modules = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return is_array($modules) && $modules !== [] ? array_values($modules) : null;
    }
}
