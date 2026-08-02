<?php

declare(strict_types=1);

namespace App\Modules\Industry\Contracts;

/**
 * A module-keyed demo seeder that also declares *what* its fixtures fill.
 *
 * IndustryDemoSeeder is enough for DemoShopSeeder, which runs every vertical's
 * fixtures blind. The operator's Demo Data picker on /platform/ops cannot: it
 * shows one chip per thing you can generate, grouped under the menu screen that
 * thing appears on, and a seeder that only says "I take a module list" gives it
 * nothing to draw.
 *
 * So a plugin that wants its screens offered there declares one chip per module
 * it seeds — the label to show, the menu route to file it under, and (where the
 * fixtures need more than one module switched on) the full set the chip
 * requires. Anything the tenant does not have that route for is simply not
 * offered, which is why the route matters more than it looks: it is both the
 * placement and the gate.
 *
 * Still optional, and still not on IndustryPlugin: a vertical that implements
 * only IndustryDemoSeeder keeps working exactly as before, its fixtures running
 * with the demo workspaces and nowhere else.
 */
interface IndustryDemoFixtures extends IndustryDemoSeeder
{
    /**
     * The chips this seeder contributes, keyed by the module each one seeds.
     *
     * `label` names the chip, `route` is the menu route it is filed under, and
     * `modules` — when the fixtures need more than the keyed module — lists
     * everything that must be enabled *and* is passed to seed(). A vehicle job
     * card needs both 'automobile' and 'service', and asking for it must bring
     * the vehicles with it or the fixture has nothing to book against.
     *
     * @return array<string, array{label: string, route: string, modules?: list<string>}>
     */
    public static function fixtures(): array;
}
