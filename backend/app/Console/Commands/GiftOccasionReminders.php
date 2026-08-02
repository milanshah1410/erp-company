<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\IteratesTenants;
use App\Models\Tenant;
use App\Modules\Industry\Gift\Services\GiftOccasionService;
use App\Support\EnabledModules;
use Illuminate\Console\Command;
use Throwable;

/**
 * The gift trade's morning reminder run.
 *
 * Tells the counter — and, where the occasion allows it, the customer — about
 * every date coming up inside its own lead time. This is the one piece of
 * automation the vertical genuinely needs: an occasion register nobody is
 * reminded from is a list a shop looks at in January and never again.
 *
 * Runs at 07:30, before shops open. A reminder that arrives at eleven has already
 * lost the morning it was meant to buy.
 *
 * Distinct from `crm:notify-customer-occasions`, which fires on the *day* of a
 * customer's own birthday and stays exactly as it is for every other trade. This
 * one fires at lead time, covers occasions belonging to people who are not the
 * customer, and can text them. Both may run in the same workspace without
 * colliding: they read different tables and say different things.
 *
 * Gated on the `gift` module per tenant, so a hardware shop's nightly run does no
 * work rather than a little. A tenant that never chose a module set has an open
 * gate, which is what `EnabledModules::forTenant()` returning null means
 * everywhere else in the system.
 */
class GiftOccasionReminders extends Command
{
    use IteratesTenants;

    protected $signature = 'gift:reminders
        {--dry-run : List what would be sent without sending anything or stamping the records}';

    protected $description = 'Remind the counter (and customers) about gift occasions falling inside their lead time';

    public function __construct(private readonly GiftOccasionService $occasions)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $reminded = 0;
        $shops = 0;

        $this->eachTenant(function (Tenant $tenant) use (&$reminded, &$shops): void {
            $modules = EnabledModules::forTenant((int) $tenant->id);

            if ($modules !== null && ! in_array('gift', $modules, true)) {
                return;
            }

            /**
             * One shop's failure must not end the run. A missing SMS gateway is
             * already swallowed inside the service, so anything reaching here is
             * unexpected — reported per tenant and stepped over, because the
             * twenty shops after this one still have customers with birthdays.
             */
            try {
                $due = $this->occasions->due();

                if ($due->isEmpty()) {
                    return;
                }

                $shops++;

                if ($this->option('dry-run')) {
                    foreach ($due as $occasion) {
                        $this->line(sprintf(
                            '  [%d] %s — %s in %d day(s)',
                            $tenant->id,
                            $occasion->personLabel(),
                            strtolower($occasion->occasionLabel()),
                            (int) ($occasion->daysUntil() ?? 0),
                        ));
                    }

                    $reminded += $due->count();

                    return;
                }

                $reminded += $this->occasions->remindDue();
            } catch (Throwable $e) {
                $this->warn("Tenant {$tenant->id}: {$e->getMessage()}");
            }
        });

        $verb = $this->option('dry-run') ? 'would remind' : 'reminded';
        $this->info("Gift occasions: {$verb} {$reminded} occasion(s) across {$shops} shop(s).");

        return self::SUCCESS;
    }
}
