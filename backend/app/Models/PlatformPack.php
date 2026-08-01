<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Modules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sellable bundle of entitlement in the module marketplace (Volume 11
 * §Module Marketplace): an Industry Pack, a Feature Pack or an AI Pack.
 *
 * A pack owns no features of its own — it grants what the product already has:
 * `modules` (which switchable slices a workspace may turn on) and `limit_bumps`
 * (how much higher its metered caps sit). Granting one therefore flows through the
 * existing gating, and there is no separate enforcement path to keep honest.
 *
 * The three types are packaging, not behaviour: `industry` packs are tied to an
 * industry template and carry that vertical's modules, `feature` packs cut across
 * verticals, and `ai` packs bundle the assistant. All three grant the same way.
 *
 * Not a {@see MarketplaceItem}. That table is an operator's on/off overlay on a
 * catalogue that ships in code (which connectors and devices we support); a pack is
 * a priced product row with its own entitlement and its own grants, so it needs a
 * table of its own rather than a `kind` on that one.
 */
class PlatformPack extends Model
{
    use \App\Models\Concerns\UsesCentralConnection;

    public const TYPES = ['industry', 'feature', 'ai'];

    public const BILLING_CYCLES = ['monthly', 'yearly', 'one_time'];

    protected $fillable = [
        'slug', 'name', 'type', 'tagline', 'description', 'icon',
        'price', 'billing_cycle', 'currency',
        'modules', 'limit_bumps', 'industry_key', 'included_in_plans',
        'is_active', 'sort_order',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'modules' => 'array',
        'limit_bumps' => 'array',
        'included_in_plans' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function grants(): HasMany
    {
        return $this->hasMany(TenantPack::class, 'platform_pack_id');
    }

    /**
     * Module keys this pack unlocks, filtered to the live catalogue so a key left
     * behind by a renamed module cannot grant something that no longer exists.
     *
     * @return list<string>
     */
    public function moduleKeys(): array
    {
        return Modules::sanitize($this->modules ?? []);
    }

    /**
     * Additive bumps to plan feature limits, restricted to the limits something
     * actually meters — a bump to an unmetered key would be a promise nothing
     * keeps.
     *
     * @return array<string, int>
     */
    public function limitBumps(): array
    {
        $allowed = \App\Support\TenantLimits::overridableKeys();

        $out = [];
        foreach ((array) ($this->limit_bumps ?? []) as $key => $value) {
            if (in_array($key, $allowed, true) && is_numeric($value) && (int) $value > 0) {
                $out[$key] = (int) $value;
            }
        }

        return $out;
    }

    /** True when the given plan bundles this pack at no extra charge. */
    public function isBundledWith(?Plan $plan): bool
    {
        if (! $plan) {
            return false;
        }

        $slugs = array_map('strval', $this->included_in_plans ?? []);

        return in_array($plan->slug, $slugs, true);
    }

    public function isFree(): bool
    {
        return (float) $this->price <= 0;
    }

    /** Perpetual grant: a one-time purchase never expires. */
    public function isPerpetual(): bool
    {
        return $this->billing_cycle === 'one_time';
    }
}
