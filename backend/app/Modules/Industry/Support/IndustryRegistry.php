<?php

declare(strict_types=1);

namespace App\Modules\Industry\Support;

use App\Modules\Industry\Contracts\IndustryPlugin;
use App\Modules\Industry\FoodBeverage\FoodBeveragePlugin;
use App\Support\Modules;
use ReflectionClass;

/**
 * The industry-plugin catalogue — which verticals the ERP sells, and what each
 * trade under them needs switched on.
 *
 * This is the only list of plugins. Registering a vertical is one line here plus
 * its folder under app/Modules/Industry; the trade picker, the module presets and
 * the /api/industries payload all derive from it.
 *
 * Two guarantees hold no matter what a plugin declares:
 *
 *  1. Module keys are sanitized against Modules::CATALOGUE, so a plugin cannot
 *     invent a module that nothing gates on and leave a dead preset behind.
 *  2. A plugin never widens entitlement. The subscriber's plan remains the
 *     ceiling (EnabledModules narrows on every read), so a trade asking for
 *     Manufacturing simply gets it only if the package sells it.
 *
 * Trade keys are flat and globally unique across verticals — they are stored on
 * tenants as `shop_profile` and must stay resolvable without knowing which
 * vertical they came from. ShopProfiles merges these with its own hand-written
 * presets and is still the single entry point onboarding talks to.
 */
final class IndustryRegistry
{
    /**
     * Registered industry plugins, in the order the picker shows them.
     *
     * @var list<class-string<IndustryPlugin>>
     */
    public const PLUGINS = [
        FoodBeveragePlugin::class,
    ];

    /**
     * Instantiated plugins.
     *
     * @return list<IndustryPlugin>
     */
    public static function plugins(): array
    {
        return array_map(static fn (string $class) => new $class(), self::PLUGINS);
    }

    /**
     * The verticals as a flat list, each carrying its trades — ready for the
     * industry picker.
     *
     * @return list<array{key: string, label: string, icon: string, description: string, trades: list<array<string, mixed>>}>
     */
    public static function industries(): array
    {
        $out = [];

        foreach (self::plugins() as $plugin) {
            $out[] = [
                'key' => $plugin->key(),
                'label' => $plugin->label(),
                'icon' => $plugin->icon(),
                'description' => $plugin->description(),
                'trades' => self::tradesFor($plugin->key()),
            ];
        }

        return $out;
    }

    /**
     * The trades of one vertical, as a flat list.
     *
     * @return list<array<string, mixed>>
     */
    public static function tradesFor(string $industryKey): array
    {
        foreach (self::plugins() as $plugin) {
            if ($plugin->key() !== $industryKey) {
                continue;
            }

            $out = [];
            foreach ($plugin->trades() as $key => $trade) {
                $out[] = self::normalizeTrade($key, $plugin->key(), $trade);
            }

            return $out;
        }

        return [];
    }

    /**
     * Every trade across every vertical, keyed by trade key.
     *
     * A duplicate trade key would silently shadow whichever plugin loaded first,
     * so the first registration wins and later ones are ignored — the same rule
     * the module catalogue uses, and the reason keys are documented as unique.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function trades(): array
    {
        $out = [];

        foreach (self::plugins() as $plugin) {
            foreach ($plugin->trades() as $key => $trade) {
                if (! array_key_exists($key, $out)) {
                    $out[$key] = self::normalizeTrade($key, $plugin->key(), $trade);
                }
            }
        }

        return $out;
    }

    /**
     * Trade presets in the shape ShopProfiles speaks, so plugin-declared trades
     * drop straight into the onboarding wizard beside the hand-written ones.
     *
     * @return array<string, array{label: string, icon: string, description: string, modules: list<string>}>
     */
    public static function tradePresets(): array
    {
        $out = [];

        foreach (self::trades() as $key => $trade) {
            $out[$key] = [
                'label' => $trade['label'],
                'icon' => $trade['icon'],
                'description' => $trade['description'],
                'modules' => $trade['modules'],
            ];
        }

        return $out;
    }

    /** Is this trade key declared by any plugin? */
    public static function hasTrade(string $key): bool
    {
        return array_key_exists($key, self::trades());
    }

    /**
     * The capability map of one trade — 'recipe' => 'available', and so on.
     * Empty for an unknown trade.
     *
     * @return array<string, string>
     */
    public static function featuresFor(string $tradeKey): array
    {
        return self::trades()[$tradeKey]['features'] ?? [];
    }

    /**
     * Is a trade capability actually built? Used by screens that are shared
     * across trades and must not offer a 'planned' feature as though it worked.
     */
    public static function featureAvailable(string $tradeKey, string $feature): bool
    {
        return (self::featuresFor($tradeKey)[$feature] ?? null) === 'available';
    }

    /*
    |--------------------------------------------------------------------------
    | Plugin file discovery
    |--------------------------------------------------------------------------
    |
    | A plugin keeps its own migrations and route files inside its folder, so
    | that dropping the folder in (and adding the PLUGINS line) is genuinely all
    | that is needed. Everything below is convention over configuration: the
    | paths are derived from where the plugin class lives, and a plugin that has
    | no Migrations/ directory or no routes.php simply contributes nothing.
    */

    /** Filesystem directory of each registered plugin. @return list<string> */
    public static function paths(): array
    {
        return array_map(
            static fn (string $class) => dirname((string) (new ReflectionClass($class))->getFileName()),
            self::PLUGINS,
        );
    }

    /**
     * Migration directories to hand to loadMigrationsFrom(). Laravel sorts
     * pending migrations by filename across every registered path, so a plugin
     * migration still runs in date order relative to database/migrations.
     *
     * @return list<string>
     */
    public static function migrationPaths(): array
    {
        return array_values(array_filter(
            array_map(static fn (string $dir) => $dir.'/Migrations', self::paths()),
            'is_dir',
        ));
    }

    /**
     * Tenant-scoped route files. Required from inside routes/tenant.php's
     * middleware group rather than loaded by a provider, so a vertical's
     * endpoints inherit tenancy, auth, permission and module gating instead of
     * having to restate them.
     *
     * @return list<string>
     */
    public static function routeFiles(): array
    {
        return self::existingFiles('routes.php');
    }

    /**
     * Unauthenticated route files, required from routes/api.php. Kept separate
     * because a public endpoint resolves its tenant from a token, not a user —
     * mixing the two into one file makes it far too easy to leave something
     * exposed by accident.
     *
     * @return list<string>
     */
    public static function publicRouteFiles(): array
    {
        return self::existingFiles('routes-public.php');
    }

    /** @return list<string> */
    private static function existingFiles(string $filename): array
    {
        return array_values(array_filter(
            array_map(static fn (string $dir) => $dir.'/'.$filename, self::paths()),
            'is_file',
        ));
    }

    /**
     * Fill in a trade's derived fields and drop module keys the catalogue does
     * not know (a plugin left behind by a renamed module).
     *
     * @param  array<string, mixed>  $trade
     * @return array<string, mixed>
     */
    private static function normalizeTrade(string $key, string $industryKey, array $trade): array
    {
        return [
            'key' => $key,
            'industry' => $industryKey,
            'label' => $trade['label'],
            'icon' => $trade['icon'] ?? 'pi pi-briefcase',
            'description' => $trade['description'] ?? '',
            'modules' => Modules::sanitize($trade['modules'] ?? []),
            'features' => $trade['features'] ?? [],
        ];
    }
}
