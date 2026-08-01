<?php

declare(strict_types=1);

namespace App\Modules\Industry\Contracts;

/**
 * One industry vertical, as a plugin.
 *
 * The ERP sells to trades, not to "businesses in general": a restaurant needs
 * table plans and kitchen dockets, a bakery needs recipes and batch expiry, a
 * dairy needs milk-collection routes. Those are not variations on one screen —
 * they are different work — but they all sit on the same spine of products,
 * stock, sales and books.
 *
 * An industry plugin is the seam between the two. It declares the trades it
 * covers, which core modules each trade runs on, and which trade-specific
 * capabilities exist for it. Onboarding, the module grid and the trade picker
 * are all rendered from those declarations, so adding a vertical is: drop a
 * folder in app/Modules/Industry, list it in IndustryRegistry::PLUGINS, and the
 * signup wizard offers it. Nothing else changes.
 *
 * Deliberately *declarative*. A plugin says what a trade is and what it needs;
 * it does not own tenancy, permissions or billing, which stay central so a
 * vertical can never quietly widen what a plan sells.
 */
interface IndustryPlugin
{
    /*
     * Optional hook, NOT part of this interface: `public function boot(): void`.
     *
     * A plugin that declares it gets called once per request by
     * IndustryServiceProvider, which checks for the method rather than requiring
     * it — most verticals are purely declarative and would only have to
     * implement it empty.
     *
     * It exists for the one thing a declarative plugin cannot express: teaching
     * a *core* model about a column the plugin added to it. A vertical that
     * extends an existing table (Automobile puts a vehicle on a service job)
     * registers the relation there with resolveRelationUsing, and attaches any
     * observer that must fire wherever the record is touched rather than only
     * inside the plugin's own endpoints.
     *
     * Keeping that on the plugin rather than in a provider preserves the rule
     * this whole layer rests on: the dependency points plugin → core and never
     * back, so unregistering a vertical leaves nothing behind but unused
     * nullable columns.
     */

    /** Stable machine key, snake_case — 'food_beverage'. Never renamed once shipped. */
    public function key(): string;

    /** How the wizard names this vertical — 'Food & Beverage'. */
    public function label(): string;

    /** PrimeIcons class for the picker. */
    public function icon(): string;

    /** One line on what the vertical covers, shown under the label. */
    public function description(): string;

    /**
     * The trades this vertical covers, keyed by trade key.
     *
     * `modules` are App\Support\Modules::CATALOGUE keys — the core slices the
     * trade runs on, used to pre-tick the onboarding module grid. Unknown keys
     * are dropped by IndustryRegistry, and the subscriber's plan is still the
     * ceiling, so a trade can ask for more than it is allowed and simply gets
     * what it bought.
     *
     * `features` are trade capabilities mapped to a status:
     *   'available' — shipped and reachable today
     *   'planned'   — named by the trade, not built yet
     *
     * The status is carried rather than implied because the picker has to be
     * honest: showing "Cake Orders" as though it worked would sell something
     * that is not there.
     *
     * @return array<string, array{
     *     label: string,
     *     icon: string,
     *     description: string,
     *     modules: list<string>,
     *     features: array<string, string>,
     * }>
     */
    public function trades(): array;
}
