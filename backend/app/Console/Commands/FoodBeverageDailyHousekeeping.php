<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\IteratesTenants;
use App\Models\Tenant;
use App\Modules\Industry\FoodBeverage\Services\MembershipService;
use App\Modules\Industry\FoodBeverage\Services\MilkSubscriptionService;
use App\Support\EnabledModules;
use Illuminate\Console\Command;

/**
 * The Food & Beverage trades' overnight housekeeping.
 *
 * Two jobs, both of which have to have happened before a shop opens:
 *
 *  1. Lapsed membership cards are marked expired. This is bookkeeping for the
 *     reports, not the thing entitlement depends on — CustomerMembership's
 *     `entitling` scope tests the dates itself, so a card stops working at midnight
 *     whether or not this ran. What it fixes is the members list, which would
 *     otherwise show last year's cards as active.
 *
 *  2. The day's milk round sheet is written from the standing orders, so the round
 *     has something to walk at five in the morning. Idempotent: the day-unique
 *     index means a sheet already written (or already corrected by hand) is left
 *     exactly as it is.
 *
 * Both are skipped for tenants that do not run the module behind them, so a
 * hardware shop's nightly run does no work rather than a little.
 */
class FoodBeverageDailyHousekeeping extends Command
{
    use IteratesTenants;

    protected $signature = 'fnb:daily
        {--skip-round : Only expire memberships, do not write the milk round sheet}
        {--skip-memberships : Only write the milk round sheet}';

    protected $description = 'Expire lapsed memberships and write the day\'s milk round sheet';

    public function __construct(
        private readonly MembershipService $memberships,
        private readonly MilkSubscriptionService $milk,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $expired = 0;
        $doorsteps = 0;

        $this->eachTenant(function (Tenant $tenant) use (&$expired, &$doorsteps): void {
            /**
             * Gated on the module, not on whether rows exist. A tenant that has
             * switched Loyalty off should not have its data touched by a nightly
             * job — the switch is meant to mean the feature is not running.
             *
             * `forTenant()` returns null for a workspace that never chose a module
             * set, and that means "gate open" everywhere else in the system, so it
             * means the same here.
             */
            $modules = EnabledModules::forTenant((int) $tenant->id);

            if (! $this->option('skip-memberships') && $this->runs($modules, 'loyalty')) {
                $expired += $this->memberships->expireLapsed();
            }

            if (! $this->option('skip-round') && $this->runs($modules, 'sales')) {
                $doorsteps += $this->milk->generateRound();
            }
        });

        $this->info("Expired {$expired} membership(s); wrote {$doorsteps} doorstep(s) onto today's round.");

        return self::SUCCESS;
    }

    /**
     * Does this tenant run the module behind a job?
     *
     * @param  list<string>|null  $modules  null = the workspace never chose, so the gate is open
     */
    private function runs(?array $modules, string $module): bool
    {
        return $modules === null || in_array($module, $modules, true);
    }
}
