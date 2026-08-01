<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use App\Support\IndustryTemplates;
use App\Support\Modules;
use App\Modules\Industry\Support\IndustryRegistry;
use Illuminate\Database\Eloquent\Model;

/**
 * An industry template — one vertical in the SaaS-owner catalogue (Volume 11
 * §"Industry Templates"). Landlord-owned: the same list serves every tenant.
 *
 * Read through App\Support\IndustryTemplates rather than querying this model
 * directly; the catalogue is read on every signup page load and is cached there.
 */
class IndustryTemplate extends Model
{
    use UsesCentralConnection;

    protected $fillable = [
        'key', 'label', 'icon', 'description',
        'modules', 'trades', 'plugin_key',
        'is_system', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'modules' => 'array',
        'trades' => 'array',
        'is_system' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * The catalogue is cached for the public signup page; invalidate here rather
     * than in the controller so a seeder run (or a tinker edit) is picked up too.
     */
    protected static function booted(): void
    {
        static::saved(static fn () => IndustryTemplates::flush());
        static::deleted(static fn () => IndustryTemplates::flush());
    }

    /**
     * The trade keys this industry offers.
     *
     * A plugin-backed template defers entirely to the plugin: the vertical's
     * trades are code, and a stale copy in this column would quietly hide a
     * trade the plugin added. Everything else reads its curated JSON list.
     *
     * @return list<string>
     */
    public function tradeKeys(): array
    {
        if ($this->plugin_key) {
            return array_keys(array_filter(
                IndustryRegistry::trades(),
                fn (array $trade) => $trade['industry'] === $this->plugin_key,
            ));
        }

        return array_values(array_map('strval', $this->trades ?? []));
    }

    /**
     * Default module set for the industry, narrowed to the live catalogue.
     *
     * @return list<string>
     */
    public function moduleKeys(): array
    {
        return Modules::sanitize($this->modules ?? []);
    }
}
