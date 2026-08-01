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
    | Host new workspaces are addressed under. A signup claims
    | `{subdomain}.{root_domain}`, recorded on tenants.subdomain + a primary
    | `domains` row (see App\Support\Subdomain).
    |
    | Reserving the address is not the same as routing to it: tenants are still
    | resolved from the authenticated user on a single host. Wildcard DNS + a
    | wildcard certificate are the infra steps that make these addresses live —
    | see the "Wildcard DNS & SSL" section of the README.
    */
    'root_domain' => env('SAAS_ROOT_DOMAIN', 'yourapp.com'),
    'workspace_scheme' => env('SAAS_WORKSPACE_SCHEME', 'https'),

    /*
    | Platform brand — the fallback white label (Volume 11 §"White Label").
    |
    | These are what an unbranded workspace, the signup journey and the platform
    | panel itself render. A tenant that has been white labelled overrides them
    | from its own settings (App\Support\Branding); a reseller deployment can
    | move the whole platform onto its own brand from .env without touching the
    | per-tenant values.
    */
    'branding' => [
        'app_name' => env('SAAS_BRAND_NAME', env('APP_NAME', 'Kosha')),
        'tagline' => env('SAAS_BRAND_TAGLINE', ''),
        'logo_url' => env('SAAS_BRAND_LOGO_URL'),
        // Keep in step with --safety-amber / --ink-blue in frontend/src/style.css.
        'primary_color' => env('SAAS_BRAND_PRIMARY', '#e8a33d'),
        'accent_color' => env('SAAS_BRAND_ACCENT', '#2c4a5e'),
    ],

    /*
    | Razorpay recurring-billing credentials + webhook secret.
    |
    | These are the *fallback*: an operator that has configured Razorpay in the
    | platform panel (Billing → Payment Gateways) overrides them from the
    | database, so key rotation does not need a deploy. Real values live in .env
    | on the deployed environment; blank locally.
    */
    'razorpay' => [
        'key' => env('RAZORPAY_KEY'),
        'secret' => env('RAZORPAY_SECRET'),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Platform billing (Volume 11 §Billing)
    |--------------------------------------------------------------------------
    |
    | Defaults for the invoices the SaaS operator raises against its tenants.
    | Everything here is overridable per-deployment from the platform panel
    | (Billing → GST & Invoicing), which stores its edits in `platform_settings`.
    | App\Support\PlatformBillingProfile layers the two, panel over config.
    |
    */
    'billing' => [

        // The seller on our subscription invoices — the SaaS operator itself.
        'seller' => [
            'legal_name' => env('PLATFORM_LEGAL_NAME'),
            'address' => env('PLATFORM_ADDRESS'),
            'gstin' => env('PLATFORM_GSTIN'),
            // GST state code the GSTIN is registered in ('24' = Gujarat).
            // Derived from the GSTIN when blank.
            'state_code' => env('PLATFORM_GST_STATE_CODE'),
            'pan' => env('PLATFORM_PAN'),
            'email' => env('PLATFORM_BILLING_EMAIL'),
            'phone' => env('PLATFORM_BILLING_PHONE'),
        ],

        'tax' => [
            // GST on SaaS in India is 18%. Zero it out (or leave the seller GSTIN
            // blank) for an unregistered / non-GST deployment.
            'rate' => (float) env('PLATFORM_GST_RATE', 18),
            // SAC 997331 — licensing services for the right to use software.
            'sac_code' => env('PLATFORM_SAC_CODE', '997331'),
            // Whether plan prices already include GST. Exclusive by default:
            // "₹999 + GST" is how Indian B2B SaaS quotes.
            'prices_include_tax' => (bool) env('PLATFORM_PRICES_INCLUDE_TAX', false),
        ],

        'invoice' => [
            // Number series prefix; the financial year is appended by
            // App\Services\Billing\InvoiceNumberService → "INV/2026-27/00001".
            'prefix' => env('PLATFORM_INVOICE_PREFIX', 'INV'),
            'terms' => env('PLATFORM_INVOICE_TERMS'),
        ],

        'renewal' => [
            // Raise the renewal invoice this many days before the period ends,
            // so the tenant has notice before the money is due.
            'lead_days' => (int) env('PLATFORM_RENEWAL_LEAD_DAYS', 5),
            // Days from issue to due date.
            'due_days' => (int) env('PLATFORM_RENEWAL_DUE_DAYS', 7),
            // Days past due at which each dunning reminder goes out. The tenant is
            // suspended by `grace_days` above, independently of these.
            'reminder_days' => [1, 3, 7],
        ],
    ],
];
