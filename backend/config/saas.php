<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SaaS / Multi-Tenancy layer configuration
|--------------------------------------------------------------------------
|
| Tenant-provisioning, subscription, and billing settings for the shared-
| database multi-tenancy model (one database, row-level tenant_id scoping,
| single domain — tenant resolved from the authenticated user).
|
*/

return [

    /*
    | Free-trial length granted to every new tenant at signup (no card).
    */
    'trial_days' => (int) env('SAAS_TRIAL_DAYS', 14),

    /*
    | Grace period (days) after a trial/subscription lapses before the tenant
    | is hard-suspended to read-only.
    */
    'grace_days' => (int) env('SAAS_GRACE_DAYS', 3),

    /*
    | Database connection the platform/landlord models (e.g. CentralUser) run
    | on. Defaults to the dedicated `central` connection. Overridden in the test
    | suite so the shared sqlite connection is reused (two :memory: connections
    | would otherwise be isolated databases).
    */
    'central_connection' => env('CENTRAL_DB_CONNECTION', 'central'),

    /*
    | Razorpay recurring-billing credentials + webhook secret. Real values
    | live in .env on the deployed environment; blank locally.
    */
    'razorpay' => [
        'key' => env('RAZORPAY_KEY'),
        'secret' => env('RAZORPAY_SECRET'),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
    ],
];
