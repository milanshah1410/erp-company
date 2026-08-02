<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\IndustryTemplate;
use Illuminate\Support\Facades\Cache;

/**
 * Read side of the industry-template catalogue (Volume 11 §"Industry Templates").
 *
 * Everything that needs to know "what verticals do we sell, and what does each
 * one start a workspace with" comes through here: the signup wizard's industry
 * step, the platform panel's tenant list, and ShopProfiles when it labels which
 * vertical a trade belongs to.
 *
 * The catalogue is read on unauthenticated page loads (the public signup) and
 * changes only when an operator edits it, so it is cached and invalidated from
 * the model's save/delete hooks — never by a TTL alone, or an operator would
 * edit a template and watch nothing happen for ten minutes.
 *
 * Two rules everything below obeys:
 *
 *  1. Trade keys are validated against ShopProfiles, and module keys against
 *     Modules::CATALOGUE. A template curating a trade that no longer exists
 *     silently drops it rather than rendering a dead tile.
 *  2. A template never widens entitlement. Its module list is a *starting
 *     selection*; the subscriber's plan is still the ceiling (EnabledModules
 *     narrows on every read), exactly as with a plugin-declared trade.
 */
final class IndustryTemplates
{
    private const CACHE_KEY = 'industry_templates_v1';

    /** Cached only as a backstop — writes invalidate explicitly. */
    private const TTL = 3600;

    /**
     * Every template, active first-class rows and retired ones alike, keyed by
     * template key and ordered by sort_order.
     *
     * @return array<string, array{key: string, label: string, icon: string, description: string, modules: list<string>, trades: list<string>, plugin_key: string|null, is_system: bool, is_active: bool, sort_order: int}>
     */
    public static function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::TTL, static function (): array {
            $out = [];

            foreach (IndustryTemplate::query()->orderBy('sort_order')->orderBy('id')->get() as $row) {
                $out[$row->key] = [
                    'key' => $row->key,
                    'label' => $row->label,
                    'icon' => $row->icon,
                    'description' => (string) $row->description,
                    'modules' => $row->moduleKeys(),
                    // Curated keys, narrowed to trades that still exist.
                    'trades' => array_values(array_intersect($row->tradeKeys(), ShopProfiles::keys())),
                    'plugin_key' => $row->plugin_key,
                    'is_system' => $row->is_system,
                    'is_active' => $row->is_active,
                    'sort_order' => $row->sort_order,
                ];
            }

            return $out;
        });
    }

    /** Drop the cached catalogue. Called from IndustryTemplate's model hooks. */
    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Templates a new signup may choose from.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function active(): array
    {
        return array_filter(self::all(), static fn (array $t) => $t['is_active']);
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /** @return array<string, mixed>|null */
    public static function find(?string $key): ?array
    {
        return self::all()[$key ?? ''] ?? null;
    }

    /**
     * The active catalogue as a flat list with each industry's trades expanded
     * to full presets — the payload the wizard's industry/trade step renders.
     *
     * Trades carry their own module set (a bakery is not a restaurant), so the
     * wizard can tick modules the moment a trade is picked without a second
     * round-trip.
     *
     * @return list<array{key: string, label: string, icon: string, description: string, modules: list<string>, trades: list<array<string, mixed>>}>
     */
    public static function catalogue(): array
    {
        $profiles = ShopProfiles::all();

        $out = [];
        foreach (self::active() as $template) {
            $trades = [];
            foreach ($template['trades'] as $tradeKey) {
                $preset = $profiles[$tradeKey] ?? null;
                if (! $preset) {
                    continue;
                }

                $trades[] = [
                    'key' => $tradeKey,
                    'label' => $preset['label'],
                    'icon' => $preset['icon'],
                    'description' => $preset['description'],
                    'modules' => Modules::sanitize($preset['modules']),
                    /**
                     * What the trade actually gives you, as declared by the plugin
                     * that owns it. Carried through so the signup wizard can list
                     * real capabilities — and mark the ones still on the roadmap —
                     * instead of inferring them from the description text.
                     * Empty for a hand-written preset with no plugin behind it.
                     */
                    'features' => ShopProfiles::featuresFor($tradeKey),
                ];
            }

            $out[] = [
                'key' => $template['key'],
                'label' => $template['label'],
                'icon' => $template['icon'],
                'description' => $template['description'],
                'modules' => $template['modules'],
                'trades' => $trades,
            ];
        }

        return $out;
    }

    /**
     * trade key => industry key. Built from the templates' curated lists, so a
     * trade offered under two industries resolves to whichever comes first in
     * sort order — the same first-registration-wins rule IndustryRegistry uses
     * for duplicate trade keys.
     *
     * @return array<string, string>
     */
    public static function tradeIndex(): array
    {
        $out = [];
        foreach (self::all() as $template) {
            foreach ($template['trades'] as $tradeKey) {
                $out[$tradeKey] ??= $template['key'];
            }
        }

        return $out;
    }

    /** Which industry does this trade sit under? Null when nothing claims it. */
    public static function industryOfTrade(?string $tradeKey): ?string
    {
        return self::tradeIndex()[$tradeKey ?? ''] ?? null;
    }

    /**
     * Resolve whatever a client sent as "industry" to a template key.
     *
     * Older wizard builds send the *trade's label* ("Garment / Apparel") in this
     * field, and API clients send free text. Rather than 422 a signup over a
     * cosmetic field, resolve in decreasing confidence: exact key, then the
     * trade it was paired with, then a case-insensitive label match. Null means
     * "no industry recorded", which is a valid state for every tenant that
     * signed up before this column existed.
     */
    public static function resolveKey(?string $industry, ?string $tradeKey = null): ?string
    {
        $industry = trim((string) $industry);

        if ($industry !== '' && isset(self::all()[$industry])) {
            return $industry;
        }

        if ($tradeKey && $resolved = self::industryOfTrade($tradeKey)) {
            return $resolved;
        }

        if ($industry !== '') {
            foreach (self::all() as $template) {
                if (strcasecmp($template['label'], $industry) === 0) {
                    return $template['key'];
                }
            }
        }

        return null;
    }

    /**
     * The module set an industry starts a workspace with when the signup named
     * an industry but no trade. Falls back to the trade preset's own list when a
     * trade *is* known, since a trade is the more specific answer.
     *
     * Whichever branch answers, the Core ERP set comes with it: an operator
     * curating a template's module list is choosing what that industry *adds*,
     * not whether it gets Sales and Accounts.
     *
     * @return list<string>
     */
    public static function modulesFor(?string $industryKey, ?string $tradeKey = null): array
    {
        if ($tradeKey && array_key_exists($tradeKey, ShopProfiles::all())) {
            return ShopProfiles::modulesFor($tradeKey);
        }

        $template = self::find($industryKey);

        return $template && $template['modules'] !== []
            ? Modules::withCore($template['modules'])
            : ShopProfiles::modulesFor(null); // core alone
    }
}
