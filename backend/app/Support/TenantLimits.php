<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Customer;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Scopes\TenantScope;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant's effective resource caps, and what it is using against them
 * (Volume 11 §"Tenant Management" → Limits).
 *
 * The plan sets the cap; a marketplace pack raises it; `tenants.limit_overrides`
 * bends it for one customer. That override exists so an operator can comp ten
 * extra seats to a client mid-negotiation without minting a bespoke plan — and so
 * the concession survives a plan change, which a hand-edited plan would not.
 *
 * An override is authoritative in both directions: a *higher* number lifts the
 * cap, an explicit `null` makes the resource unlimited, and a *lower* number
 * tightens it. Only the three metered resources below can be overridden — the
 * ones something actually enforces (EnforcePlanLimit). Module entitlement is
 * comped separately, through the Access panel's `module_grants` setting, so the
 * two never disagree about what a workspace may switch on.
 *
 * Nothing here is retroactive: a tenant already past a cap keeps every record it
 * has and simply cannot create more. Enforcement is on the create path only, so
 * tightening a limit can never make existing data unreadable.
 */
final class TenantLimits
{
    /**
     * The resources that are actually metered.
     *
     * resource key => [model, feature_limits key, human label]
     *
     * @var array<string, array{0: class-string<Model>, 1: string, 2: string}>
     */
    public const METERED = [
        'products' => [Product::class, 'max_products', 'products'],
        'warehouses' => [Warehouse::class, 'max_warehouses', 'warehouses'],
        'users' => [User::class, 'max_users', 'users'],
        // Volume 11 §"Tenant Usage" reports customers alongside products, so the
        // cap lives here too rather than being metered in one place and enforced
        // in another. No shipped plan sets max_customers, which reads as
        // unlimited — the key exists so an owner *can* cap it, per plan or per
        // tenant, without a migration.
        'customers' => [Customer::class, 'max_customers', 'customers'],
    ];

    /** The `feature_limits` keys an override may set. */
    public static function overridableKeys(): array
    {
        return array_values(array_map(static fn (array $r) => $r[1], self::METERED));
    }

    /** The tenant's current plan, or null when it has no subscription on record. */
    public static function planFor(int $tenantId): ?Plan
    {
        return Subscription::with('plan')
            ->where('tenant_id', $tenantId)
            ->latest('id')
            ->first()?->plan;
    }

    /**
     * The tenant's effective limits: the plan's `feature_limits`, raised by any
     * marketplace pack it holds, with a per-tenant override on top.
     *
     * The order matters. A pack *bumps* the plan's number (it is a bought
     * concession, so it stacks); an override *replaces* whatever that arithmetic
     * produced (it is a negotiated figure, and an operator typing 25 means 25).
     *
     * @return array<string, mixed>
     */
    public static function effective(int $tenantId, ?Plan $plan = null, ?Tenant $tenant = null): array
    {
        $plan ??= self::planFor($tenantId);
        $tenant ??= Tenant::find($tenantId);

        $limits = (array) ($plan?->feature_limits ?? []);

        foreach (PackEntitlement::limitBumpsFor($tenantId) as $key => $bump) {
            // An unlimited (null / non-numeric) plan limit cannot be raised — it is
            // already at the top.
            if (is_numeric($limits[$key] ?? null)) {
                $limits[$key] = (int) $limits[$key] + $bump;
            }
        }

        foreach (self::sanitizeOverrides((array) ($tenant?->limit_overrides ?? [])) as $key => $value) {
            // array_merge would drop an explicit null; assignment keeps it, which
            // is the whole point — null means "unlimited for this tenant".
            $limits[$key] = $value;
        }

        return $limits;
    }

    /**
     * One effective limit. Null means unlimited — both "the plan sets no cap"
     * and "an operator lifted the cap for this tenant" arrive here as null.
     */
    public static function limit(int $tenantId, string $key, ?Plan $plan = null, ?Tenant $tenant = null): mixed
    {
        return self::effective($tenantId, $plan, $tenant)[$key] ?? null;
    }

    /**
     * Current consumption of every metered resource.
     *
     * Counted with an explicit tenant_id and the tenant scope lifted, so this
     * works identically inside a tenant request and from the platform panel,
     * where there is no tenant context to read.
     *
     * @return array<string, int>
     */
    public static function usage(int $tenantId): array
    {
        $out = [];

        foreach (self::METERED as $resource => [$model, , ]) {
            $out[$resource] = self::count($model, $tenantId);
        }

        return $out;
    }

    /** Count one metered resource for a tenant. */
    public static function count(string $model, int $tenantId): int
    {
        return $model::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->count();
    }

    /**
     * Limits + usage in one payload, ready for the platform panel's Limits
     * dialog: what the plan sells, what this tenant was comped, and how close it
     * is to the ceiling.
     *
     * @return array{plan: array{slug: string, name: string}|null, resources: list<array<string, mixed>>, plan_features: array<string, mixed>, overrides: array<string, mixed>, pack_bumps: array<string, int>}
     */
    public static function summary(int $tenantId): array
    {
        $tenant = Tenant::find($tenantId);
        $plan = self::planFor($tenantId);

        $planLimits = (array) ($plan?->feature_limits ?? []);
        $overrides = self::sanitizeOverrides((array) ($tenant?->limit_overrides ?? []));
        $bumps = PackEntitlement::limitBumpsFor($tenantId);
        $usage = self::usage($tenantId);

        $resources = [];
        foreach (self::METERED as $resource => [, $limitKey, $label]) {
            $hasOverride = array_key_exists($limitKey, $overrides);
            $bump = is_numeric($planLimits[$limitKey] ?? null) ? ($bumps[$limitKey] ?? 0) : 0;
            // Mirrors effective(): pack bumps raise the plan's number, an override
            // replaces the result outright.
            $withBump = is_numeric($planLimits[$limitKey] ?? null)
                ? (int) $planLimits[$limitKey] + $bump
                : ($planLimits[$limitKey] ?? null);
            $effective = $hasOverride ? $overrides[$limitKey] : $withBump;
            $used = $usage[$resource] ?? 0;
            $unlimited = ! is_numeric($effective);

            $resources[] = [
                'resource' => $resource,
                'key' => $limitKey,
                'label' => ucfirst($label),
                'plan_limit' => is_numeric($planLimits[$limitKey] ?? null) ? (int) $planLimits[$limitKey] : null,
                // Extra headroom bought through a marketplace pack, shown so the
                // dialog's arithmetic is legible: plan + pack, or the override.
                'pack_bump' => $bump ?: null,
                'override' => $hasOverride && is_numeric($effective) ? (int) $effective : null,
                'has_override' => $hasOverride,
                'limit' => $unlimited ? null : (int) $effective,
                'unlimited' => $unlimited,
                'usage' => $used,
                'remaining' => $unlimited ? null : max(0, (int) $effective - $used),
                // A tenant can sit above its cap after a downgrade or a tightened
                // override; the panel flags it rather than pretending it fits.
                'exceeded' => ! $unlimited && $used > (int) $effective,
            ];
        }

        return [
            'plan' => $plan ? ['slug' => $plan->slug, 'name' => $plan->name] : null,
            'resources' => $resources,
            // Everything else the plan declares (barcode, reports, support tier,
            // module list) — shown for context, changed by changing the plan.
            'plan_features' => array_diff_key($planLimits, array_flip(self::overridableKeys())),
            'overrides' => $overrides,
            'pack_bumps' => $bumps,
        ];
    }

    /**
     * Keep only overridable keys, and normalise each value to an int or null
     * ("" and "unlimited" from a form both mean no cap).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, int|null>
     */
    public static function sanitizeOverrides(array $overrides): array
    {
        $allowed = self::overridableKeys();

        $out = [];
        foreach ($overrides as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                continue;
            }

            $out[$key] = is_numeric($value) ? max(0, (int) $value) : null;
        }

        return $out;
    }
}
