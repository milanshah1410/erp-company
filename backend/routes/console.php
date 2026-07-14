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

Schedule::command('backup:run')->daily()->at('02:00');
Schedule::command('backup:clean')->daily()->at('01:30');

// SaaS: trial-ending reminders + grace-period suspensions.
Schedule::command('subscriptions:process')->daily()->at('06:00');
