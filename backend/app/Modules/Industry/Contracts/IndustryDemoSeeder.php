<?php

declare(strict_types=1);

namespace App\Modules\Industry\Contracts;

/**
 * A vertical's demo fixtures, for DemoShopSeeder.
 *
 * The generic DemoDataGenerator only knows the core spine — products, orders,
 * stock, payroll. That is deliberate: it also runs during a real signup ("load
 * sample data"), and a fertiliser dealer should not wake up holding blood
 * reports. But it means a plugin's own screens open empty in the demo
 * workspace for its trade, which is exactly the workspace someone opens to see
 * what the vertical does.
 *
 * So a plugin may ship its own fixtures here. Discovered by convention —
 * `App\Modules\Industry\<Vertical>\Database\DemoSeeder` — rather than declared
 * on IndustryPlugin, because it is a development fixture and the plugin
 * interface should stay about what the product *sells*.
 *
 * Implementations must be idempotent: DemoShopSeeder is re-run whenever a
 * preset changes, and a second run must refresh the same workspace rather than
 * double it.
 */
interface IndustryDemoSeeder
{
    /**
     * Seed this vertical's fixtures into the current tenant.
     *
     * Runs inside the workspace's tenant context with the demo admin
     * authenticated as $userId. `$modules` is the workspace's enabled set — a
     * seeder covering two modules (a lab and a hospital store) must check it
     * and seed only what that trade actually switched on, or a standalone
     * pathology lab ends up holding ward indents it has no menu to open.
     *
     * @param  list<string>  $modules
     */
    public function seed(array $modules, int $userId): void;
}
