<?php

declare(strict_types=1);

namespace App\Modules\Industry\Contracts;

/**
 * A vertical's demo fixtures dispatched by *trade* rather than by module.
 *
 * The other half of IndustryDemoSeeder. Most verticals only need to know which
 * modules a workspace switched on, but some run the same module set across
 * trades that should look nothing alike — a cafe and a bakery are both Food &
 * Beverage with the same screens, and seeding cake orders into the cafe would
 * misrepresent both. Those plugins take the trade key instead and decide from
 * it. A plugin uses one contract or the other, never both.
 *
 * Discovered by convention from the plugin's namespace —
 * `App\Modules\Industry\<Vertical>\Database\<Vertical>DemoSeeder` — so a new
 * vertical needs no edit anywhere outside its own folder. Instantiated with the
 * seeding user's id, hence the class-string (not instance) that
 * IndustryRegistry::tradeDemoSeeders() hands back.
 *
 * `fixtures()` is what puts the trade on the operator's Demo Data picker; see
 * IndustryDemoFixtures for what the chip descriptor means. Implementations must
 * be idempotent — the picker can be run against the same workspace repeatedly.
 */
interface IndustryTradeDemoSeeder
{
    /** Seed the fixtures for one trade. Unknown trades must be a no-op. */
    public function seed(string $trade): void;

    /**
     * The chips this seeder contributes, keyed by trade.
     *
     * `modules` here is a visibility gate only — the trade is what gets passed
     * to seed(), not the module list.
     *
     * @return array<string, array{label: string, route: string, modules?: list<string>}>
     */
    public static function fixtures(): array;
}
