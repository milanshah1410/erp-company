<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('inventory:generate-alerts')->daily();
Schedule::command('notifications:check-due-invoices')->daily();
Schedule::command('manufacturing:notify-pending')->daily();
Schedule::command('brokers:notify-commission-due')->daily();
Schedule::command('notifications:outstanding-balances')->daily();
Schedule::command('expenses:notify-pending-approval')->daily();
Schedule::command('crm:notify-due-followups')->daily();
Schedule::command('crm:notify-customer-occasions')->dailyAt('08:00');

// Food & Beverage trades. Runs at 04:00 so both jobs have finished before the
// earliest shop opens: a dairy's round is walked before dawn and needs its sheet,
// and a café's members list should not show yesterday's lapsed cards as active.
Schedule::command('fnb:daily')->dailyAt('04:00');

Schedule::command('backup:run')->daily()->at('02:00');
Schedule::command('backup:clean')->daily()->at('01:30');

// SaaS billing, in the order the money moves: renewal invoices and dunning
// reminders first, then the grace-period sweep that suspends whoever still has
// not paid. Running renewals second would suspend a tenant on the same day it
// was first told it owed anything.
Schedule::command('subscriptions:renew')->daily()->at('05:30');

// SaaS: trial-ending reminders + grace-period suspensions.
Schedule::command('subscriptions:process')->daily()->at('06:00');

// SaaS: tenant usage metering (Volume 11 §"Tenant Usage"). Counters drain from
// the cache buffer every five minutes; the snapshot metrics are recorded once a
// night, late enough that the day's counters have settled.
Schedule::command('usage:flush')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('usage:snapshot --fresh-storage')->dailyAt('23:45');
