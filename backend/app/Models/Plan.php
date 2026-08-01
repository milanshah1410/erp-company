<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Modules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    // Central-DB model: pin to the landlord connection so it keeps resolving
    // to the central database even while a tenant connection is the default.
    use \App\Models\Concerns\UsesCentralConnection;

    protected $fillable = [
        'slug', 'name', 'price', 'billing_cycle', 'currency',
        'is_custom', 'is_active', 'sort_order', 'feature_limits',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_custom' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'feature_limits' => 'array',
    ];

    /**
     * Slugs of retired plans, mapped to the tier that replaced them.
     *
     * A plan slug leaks out of the product — into pricing links, saved API
     * payloads and half-finished signups — so retiring a tier cannot mean
     * invalidating its slug. The retired row stays in the table (deactivated, so
     * it is off the pricing page) and everything that resolves a slug to a plan
     * goes through findBySlug(), which forwards it here.
     *
     * @var array<string, string>
     */
    public const LEGACY_SLUGS = [
        'growth' => 'business',
    ];

    /**
     * Resolve a plan by slug, forwarding retired slugs to their replacement.
     *
     * Prefers an active plan: a legacy slug only resolves to the retired row if
     * its replacement is somehow missing, which keeps a stale `?plan=growth`
     * link selling the current equivalent rather than a dormant tier.
     */
    public static function findBySlug(?string $slug): ?self
    {
        $slug = trim((string) $slug);
        if ($slug === '') {
            return null;
        }

        $replacement = self::LEGACY_SLUGS[$slug] ?? null;

        return ($replacement ? self::where('slug', $replacement)->first() : null)
            ?? self::where('slug', $slug)->first();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Read a single feature limit. Returns null when the plan places no limit
     * on that feature (treat null as "unlimited").
     */
    public function limit(string $key): mixed
    {
        return $this->feature_limits[$key] ?? null;
    }

    /**
     * The module keys a subscriber on this plan may switch on (onboarding §3).
     *
     * `feature_limits.modules` holds either the literal `['*']` — Enterprise,
     * everything in the catalogue — or an explicit subset. A plan that declares
     * nothing fails open to the full catalogue, so plans created before module
     * gating existed keep working.
     *
     * @return list<string>
     */
    public function allowedModules(): array
    {
        $declared = $this->feature_limits['modules'] ?? null;

        if (! is_array($declared) || $declared === [] || in_array('*', $declared, true)) {
            return Modules::keys();
        }

        return Modules::sanitize($declared);
    }

    /** True when the plan puts no restriction on the module set. */
    public function allowsAllModules(): bool
    {
        return count($this->allowedModules()) === count(Modules::keys());
    }
}
