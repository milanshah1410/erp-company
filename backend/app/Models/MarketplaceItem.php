<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\DeviceManager\Support\DeviceRegistry;
use App\Support\IntegrationCatalogue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of the SaaS owner's marketplace: their commercial decision about a
 * single catalogue entry (Volume 11 §"Integration Marketplace" / §"Device
 * Marketplace").
 *
 * Platform-owned, so no tenant scope — these rows describe what is on sale, not
 * what anyone bought. The entry's identity (label, fields, driver class) lives in
 * code; this table carries only the levers an operator pulls at runtime.
 *
 * Keys, by kind:
 *   integration — 'razorpay', 'meesho', … (App\Support\IntegrationCatalogue)
 *   device      — 'barcode' for a whole device class, 'barcode:zebra' for one
 *                 brand adapter. Both exist so an owner can withdraw a flaky
 *                 brand without withdrawing the category.
 */
class MarketplaceItem extends Model
{
    public const KIND_INTEGRATION = 'integration';

    public const KIND_DEVICE = 'device';

    protected $fillable = [
        'kind', 'key', 'is_enabled', 'is_beta', 'plan_slugs', 'note', 'sort_order',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'is_beta' => 'boolean',
        'plan_slugs' => 'array',
    ];

    public function scopeKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    /**
     * Is this offering available to a subscriber on $planSlug?
     *
     * An empty/absent `plan_slugs` means every plan — the common case, and the
     * default for a newly synced row, so adding a connector does not silently
     * hide it from everyone until an operator ticks boxes.
     */
    public function availableToPlan(?string $planSlug): bool
    {
        if (! $this->is_enabled) {
            return false;
        }

        $slugs = $this->plan_slugs;
        if (! is_array($slugs) || $slugs === []) {
            return true;
        }

        // No plan on record (mid-provisioning, or a legacy row) → don't withhold;
        // the gate is commercial packaging, not a security boundary.
        return $planSlug === null || in_array($planSlug, $slugs, true);
    }

    /**
     * The device key for a class, or a class + brand pair.
     */
    public static function deviceKey(string $type, ?string $driver = null): string
    {
        return $driver === null || $driver === '' ? $type : "{$type}:{$driver}";
    }

    /**
     * Ensure a row exists for every key the code catalogues, and report what was
     * added.
     *
     * Called by the marketplace screens rather than only from a seeder: a deploy
     * that ships a new connector should surface it on the panel immediately, and
     * an operator should never have to run a seeder to see what they now sell.
     * Existing rows are never touched — an operator's disable survives a deploy.
     *
     * New entries default to enabled, except roadmap ones: a 'planned'
     * integration ships off, because nothing behind it works yet.
     */
    public static function syncCatalogue(): int
    {
        $added = 0;
        $existing = static::query()->get()->keyBy(fn (self $i) => "{$i->kind}:{$i->key}");

        $ensure = function (string $kind, string $key, int $sort, bool $enabled) use (&$added, $existing): void {
            if ($existing->has("{$kind}:{$key}")) {
                return;
            }

            static::create([
                'kind' => $kind,
                'key' => $key,
                'is_enabled' => $enabled,
                'is_beta' => false,
                'plan_slugs' => null,
                'sort_order' => $sort,
            ]);
            $added++;
        };

        $sort = 0;
        foreach (IntegrationCatalogue::all() as $key => $meta) {
            $ensure(self::KIND_INTEGRATION, $key, $sort += 10, ($meta['status'] ?? 'live') === 'live');
        }

        $sort = 0;
        foreach (DeviceRegistry::drivers() as $type => $brands) {
            $ensure(self::KIND_DEVICE, self::deviceKey($type), $sort += 100, true);

            $brandSort = $sort;
            foreach (array_keys($brands) as $driver) {
                $ensure(self::KIND_DEVICE, self::deviceKey($type, $driver), $brandSort += 10, true);
            }
        }

        return $added;
    }
}
