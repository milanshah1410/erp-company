<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SaaS / Multi-Tenancy layer configuration
|--------------------------------------------------------------------------
|
| Central place for tenant-provisioning, subscription, and billing settings
| that sit on top of stancl/tenancy. Kept separate from config/tenancy.php
| (the package's own config) so package upgrades don't clobber our settings.
|
*/

return [

    /*
    | The bare domain that tenant subdomains hang off of. Locally this is
    | "localhost" (so tenants live at acme.localhost:8000 — *.localhost
    | resolves to 127.0.0.1 automatically on modern OSes). In production set
    | APP_TENANT_DOMAIN=yourapp.com so tenants become acme.yourapp.com.
    |
    | Note: subdomains of a bare IP (127.0.0.1) are not possible, so the
    | tenant subdomain routing is only wired for hostname central domains.
    */
    'tenant_domain' => env('APP_TENANT_DOMAIN', 'localhost'),

    /*
    | Subdomains that can never be registered by a tenant because they are
    | reserved for platform infrastructure. Compared case-insensitively.
    */
    'reserved_subdomains' => [
        'www', 'api', 'admin', 'app', 'mail', 'ftp', 'smtp', 'imap', 'pop',
        'staging', 'stage', 'dev', 'test', 'demo', 'billing', 'support',
        'help', 'docs', 'blog', 'status', 'cdn', 'assets', 'static',
        'dashboard', 'portal', 'my', 'account', 'accounts', 'login', 'signup',
        'register', 'central', 'landlord', 'tenant', 'tenants', 'ns1', 'ns2',
    ],

    /*
    | Subdomain format: lowercase alphanumeric + hyphen, 3-30 chars,
    | cannot start or end with a hyphen.
    */
    'subdomain_regex' => '/^(?!-)[a-z0-9-]{3,30}(?<!-)$/',

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
    | Razorpay recurring-billing credentials + webhook secret. Real values
    | live in .env on the deployed environment; blank locally.
    */
    'razorpay' => [
        'key' => env('RAZORPAY_KEY'),
        'secret' => env('RAZORPAY_SECRET'),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
    ],
];
