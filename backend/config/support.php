<?php

/**
 * Support & Customer Success configuration for the SaaS-owner panel.
 *
 * The SLA matrix is (tier × priority) → hours. The tier comes from the tenant's
 * plan (`feature_limits.support`: email | priority | dedicated); the priority is
 * whatever triage set when the ticket was opened. Both are snapshotted onto the
 * ticket, so editing this file only affects tickets opened afterwards.
 *
 * Hours are elapsed calendar hours, deliberately — a 24×7 SaaS product cannot
 * promise "4 business hours" to tenants spread across time zones, and a
 * business-hours clock would need a holiday calendar per region to mean
 * anything. Tune the numbers, not the unit.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | SLA tiers
    |--------------------------------------------------------------------------
    | `plan_map` translates a plan's `feature_limits.support` value into a tier
    | key below. Anything unmapped (or a tenant with no subscription) falls back
    | to `default_tier`.
    */

    'default_tier' => 'standard',

    'plan_map' => [
        'email' => 'standard',
        'priority' => 'priority',
        'dedicated' => 'premium',
    ],

    'tiers' => [
        'standard' => [
            'label' => 'Standard',
            // priority => [first response hours, resolution hours]
            'targets' => [
                'urgent' => ['response' => 8, 'resolution' => 24],
                'high' => ['response' => 12, 'resolution' => 48],
                'normal' => ['response' => 24, 'resolution' => 96],
                'low' => ['response' => 48, 'resolution' => 168],
            ],
        ],
        'priority' => [
            'label' => 'Priority',
            'targets' => [
                'urgent' => ['response' => 4, 'resolution' => 12],
                'high' => ['response' => 8, 'resolution' => 24],
                'normal' => ['response' => 12, 'resolution' => 48],
                'low' => ['response' => 24, 'resolution' => 96],
            ],
        ],
        'premium' => [
            'label' => 'Premium',
            'targets' => [
                'urgent' => ['response' => 1, 'resolution' => 8],
                'high' => ['response' => 2, 'resolution' => 12],
                'normal' => ['response' => 6, 'resolution' => 24],
                'low' => ['response' => 12, 'resolution' => 48],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Breach warning window
    |--------------------------------------------------------------------------
    | A ticket inside this many hours of a due date is reported as `due_soon` so
    | the queue can be worked before it breaches.
    */

    'due_soon_hours' => 2,

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    | Ticket files are platform data (they describe the platform↔tenant
    | relationship, not tenant business records), so they do NOT go through
    | App\Support\TenantStorage — they live on a central disk, private, served
    | only through the permission-checked download route.
    */

    'attachments' => [
        'disk' => env('SUPPORT_ATTACHMENT_DISK', 'local'),
        'directory' => 'support/tickets',
        'max_kb' => 10240, // 10 MB
        'max_per_ticket' => 20,
        'mimes' => [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf',
            'txt', 'log', 'csv', 'xls', 'xlsx', 'doc', 'docx', 'zip',
        ],
    ],

];
