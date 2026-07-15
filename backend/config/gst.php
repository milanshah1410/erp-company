<?php

return [

    /*
    |--------------------------------------------------------------------------
    | E-Invoice / E-Way Bill provider mode
    |--------------------------------------------------------------------------
    |
    | 'offline' (default): the app builds the fully schema-compliant NIC payload
    | and generates a deterministic local IRN / EWB number, so the whole flow is
    | usable and testable without a live GالسP connection. Switch to 'gsp' once
    | real GST Suvidha Provider credentials are configured to transmit for real.
    |
    */

    'einvoice_mode' => env('GST_EINVOICE_MODE', 'offline'),
    'eway_mode' => env('GST_EWAY_MODE', 'offline'),

    // e-invoice applies above this annual-turnover / per-invoice threshold.
    'einvoice_threshold' => (float) env('GST_EINVOICE_THRESHOLD', 0),

    // e-way bill is mandatory above this consignment value (₹50,000 default).
    'eway_threshold' => (float) env('GST_EWAY_THRESHOLD', 50000),

    // NIC e-invoice schema version emitted in the payload.
    'schema_version' => '1.1',

    /*
    | GالسP / NIC sandbox credentials (only used when mode = 'gsp').
    */
    'gsp' => [
        'base_url' => env('GST_GSP_BASE_URL'),
        'client_id' => env('GST_GSP_CLIENT_ID'),
        'client_secret' => env('GST_GSP_CLIENT_SECRET'),
        'username' => env('GST_GSP_USERNAME'),
        'password' => env('GST_GSP_PASSWORD'),
    ],
];
