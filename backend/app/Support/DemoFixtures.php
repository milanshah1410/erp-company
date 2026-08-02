<?php

declare(strict_types=1);

namespace App\Support;

use App\Modules\Industry\Contracts\IndustryDemoFixtures;
use App\Modules\Industry\Support\IndustryRegistry;
use Illuminate\Support\Str;
use ReflectionClass;
use Throwable;

/**
 * The industry plugins' demo fixtures, as entries in the operator's Demo Data
 * picker (project-stack.md §7).
 *
 * DemoDataGenerator only knows the core spine, and deliberately so — it also
 * runs on a real signup, and a fertiliser dealer should not wake up holding
 * blood reports. The consequence was that every vertical's own screens (the
 * trial room, the ward store, the milk round, the shade card) had no chip at
 * all on /platform/ops: the operator could fill Sales and Inventory for a
 * restaurant tenant and still hand over an empty Recipes screen.
 *
 * The fixtures to fill them already existed — 20-odd `Database\DemoSeeder`
 * classes wired only to DemoShopSeeder. This exposes them, so the picker offers
 * one chip per vertical feature, filed under the same menu screen the ERP shows
 * it on.
 *
 * Two things are deliberately unlike the generator:
 *
 *  - **Volume does not apply.** These are curated fixtures whose value is the
 *    interesting states in them — one overdue trial, one part-spent voucher,
 *    one cheque that bounced. Multiplying that by 1,000 destroys the very thing
 *    it demonstrates, so a fixture chip seeds its set once at any volume and
 *    the picker says so.
 *  - **Re-running refreshes.** Every fixture is idempotent (matched on a
 *    prefixed key), so picking the same chip twice updates the same rows
 *    instead of stacking a second copy.
 */
final class DemoFixtures
{
    /** Marks a picker key as a fixture rather than a generator module. */
    public const PREFIX = 'fx:';

    /**
     * Every fixture chip the installed plugins declare.
     *
     * @return array<string, array{
     *     label: string,
     *     route: string,
     *     modules: list<string>,
     *     seeder: class-string,
     *     arg: string,
     *     trade: bool,
     * }>
     */
    public static function catalogue(): array
    {
        $out = [];

        foreach (IndustryRegistry::demoSeeders() as $seeder) {
            if (! $seeder instanceof IndustryDemoFixtures) {
                continue;
            }

            $slug = self::slug($seeder::class);

            foreach ($seeder::fixtures() as $module => $chip) {
                $out[self::PREFIX.$slug.':'.$module] = [
                    'label' => $chip['label'],
                    'route' => $chip['route'],
                    'modules' => $chip['modules'] ?? [$module],
                    'seeder' => $seeder::class,
                    'arg' => $module,
                    'trade' => false,
                ];
            }
        }

        foreach (IndustryRegistry::tradeDemoSeeders() as $class) {
            $slug = self::slug($class);

            foreach ($class::fixtures() as $trade => $chip) {
                $out[self::PREFIX.$slug.':'.$trade] = [
                    'label' => $chip['label'],
                    'route' => $chip['route'],
                    'modules' => $chip['modules'] ?? [],
                    'seeder' => $class,
                    'arg' => $trade,
                    'trade' => true,
                ];
            }
        }

        return $out;
    }

    /** True for keys this class owns, so callers can split a mixed selection. */
    public static function owns(string $key): bool
    {
        return str_starts_with($key, self::PREFIX);
    }

    /**
     * Seed the chosen fixtures into the current tenant.
     *
     * Must run inside the target tenant's TenantContext, like everything else
     * the demo tooling does. Each seeder is wrapped on its own: one vertical's
     * broken fixture costs its own rows and nothing else, exactly as it does in
     * DemoShopSeeder.
     *
     * A module-keyed seeder is handed the chip's full module list rather than
     * the one key it is filed under — that list *is* its dependencies, and a
     * job-card fixture with no vehicles to book against would write nothing.
     *
     * @param  list<string>  $keys
     * @return array{seeded: list<string>, failed: array<string, string>}  labels seeded · label => error
     */
    public static function run(array $keys, ?int $userId): array
    {
        $catalogue = self::catalogue();
        $seeded = [];
        $failed = [];

        foreach ($keys as $key) {
            $chip = $catalogue[$key] ?? null;

            if (! $chip) {
                continue;
            }

            try {
                if ($chip['trade']) {
                    (new $chip['seeder']($userId))->seed($chip['arg']);
                } else {
                    (new $chip['seeder'])->seed($chip['modules'], (int) $userId);
                }

                $seeded[] = $chip['label'];
            } catch (Throwable $e) {
                $failed[$chip['label']] = $e->getMessage();
            }
        }

        return ['seeded' => $seeded, 'failed' => $failed];
    }

    /**
     * The vertical a seeder belongs to, from its namespace — Retail's seeder
     * becomes 'retail'. Keys have to carry it: four verticals seed the module
     * called 'service', and without the vertical in the key an automobile
     * workshop's job cards and a mobile shop's repair jobs would be one chip.
     */
    private static function slug(string $class): string
    {
        $parts = explode('\\', (new ReflectionClass($class))->getNamespaceName());
        // …\Industry\<Vertical>\Database → the vertical.
        $vertical = $parts[count($parts) - 2] ?? class_basename($class);

        return Str::snake($vertical);
    }
}
