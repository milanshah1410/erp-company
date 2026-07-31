<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Customer payment-collection gateways (SRS §16)
|--------------------------------------------------------------------------
|
| Catalogue of the online payment gateways a tenant can enable to collect
| money against sales invoices. Credentials are NOT stored here — each tenant
| configures its own account (encrypted) in the payment_gateway_accounts table.
| This file only describes each gateway: the credential fields its config form
| needs, its supported currencies, and the test/live API endpoints its driver
| talks to.
|
| (This is separate from config/saas.php, which holds the PLATFORM Razorpay
| account used to bill tenants for their subscription.)
|
*/

return [

    // Where the customer-facing hosted pay page lives. In production the SPA is
    // served from the same origin as the API (APP_URL); in local dev the Vite
    // server runs on a different port, so FRONTEND_URL overrides it.
    'pay_page_base' => rtrim((string) env('FRONTEND_URL', env('APP_URL', 'http://localhost')), '/'),

    // Minutes a generated payment link stays payable before it is treated as expired.
    'link_ttl_minutes' => (int) env('PAYMENT_LINK_TTL_MINUTES', 60 * 24 * 7),

    'gateways' => [

        'razorpay' => [
            'label' => 'Razorpay',
            'currencies' => ['INR'],
            'export' => false,
            'flow' => 'redirect',
            'fields' => [
                'key_id' => ['label' => 'Key ID', 'secret' => false, 'required' => true],
                'key_secret' => ['label' => 'Key Secret', 'secret' => true, 'required' => true],
                'webhook_secret' => ['label' => 'Webhook Secret', 'secret' => true, 'required' => false],
            ],
            'endpoints' => [
                'test' => 'https://api.razorpay.com/v1',
                'live' => 'https://api.razorpay.com/v1',
            ],
        ],

        'cashfree' => [
            'label' => 'Cashfree',
            'currencies' => ['INR'],
            'export' => false,
            'flow' => 'redirect',
            'fields' => [
                'app_id' => ['label' => 'App ID', 'secret' => false, 'required' => true],
                'secret_key' => ['label' => 'Secret Key', 'secret' => true, 'required' => true],
                'webhook_secret' => ['label' => 'Webhook Secret', 'secret' => true, 'required' => false],
            ],
            'endpoints' => [
                'test' => 'https://sandbox.cashfree.com/pg',
                'live' => 'https://api.cashfree.com/pg',
            ],
            'api_version' => '2023-08-01',
        ],

        'phonepe' => [
            'label' => 'PhonePe',
            'currencies' => ['INR'],
            'export' => false,
            'flow' => 'redirect',
            'fields' => [
                'merchant_id' => ['label' => 'Merchant ID', 'secret' => false, 'required' => true],
                'salt_key' => ['label' => 'Salt Key', 'secret' => true, 'required' => true],
                'salt_index' => ['label' => 'Salt Index', 'secret' => false, 'required' => true],
            ],
            'endpoints' => [
                'test' => 'https://api-preprod.phonepe.com/apis/pg-sandbox',
                'live' => 'https://api.phonepe.com/apis/hermes',
            ],
        ],

        // Paytm "All-in-One" PG. Two steps: an Initiate Transaction call returns
        // a txnToken, which is then form-posted to the hosted payment page — so
        // the flow is form_post even though the first leg is a REST call.
        'paytm' => [
            'label' => 'Paytm Business',
            'currencies' => ['INR'],
            'export' => false,
            'flow' => 'form_post',
            'fields' => [
                'merchant_id' => ['label' => 'Merchant ID (MID)', 'secret' => false, 'required' => true],
                'merchant_key' => ['label' => 'Merchant Key', 'secret' => true, 'required' => true],
                // Paytm issues 'WEBSTAGING' for test and 'DEFAULT' for live.
                'website' => ['label' => 'Website Name', 'secret' => false, 'required' => true],
                'industry_type' => ['label' => 'Industry Type ID', 'secret' => false, 'required' => false],
            ],
            'endpoints' => [
                'test' => 'https://securegw-stage.paytm.in',
                'live' => 'https://securegw.paytm.in',
            ],
        ],

        'payu' => [
            'label' => 'PayU',
            'currencies' => ['INR'],
            'export' => false,
            'flow' => 'form_post',
            'fields' => [
                'merchant_key' => ['label' => 'Merchant Key', 'secret' => false, 'required' => true],
                'merchant_salt' => ['label' => 'Merchant Salt', 'secret' => true, 'required' => true],
            ],
            'endpoints' => [
                'test' => 'https://test.payu.in/_payment',
                'live' => 'https://secure.payu.in/_payment',
            ],
        ],

        'ccavenue' => [
            'label' => 'CCAvenue',
            'currencies' => ['INR'],
            'export' => false,
            'flow' => 'form_post',
            'fields' => [
                'merchant_id' => ['label' => 'Merchant ID', 'secret' => false, 'required' => true],
                'access_code' => ['label' => 'Access Code', 'secret' => false, 'required' => true],
                'working_key' => ['label' => 'Working Key', 'secret' => true, 'required' => true],
            ],
            'endpoints' => [
                'test' => 'https://test.ccavenue.com/transaction/transaction.do?command=initiateTransaction',
                'live' => 'https://secure.ccavenue.com/transaction/transaction.do?command=initiateTransaction',
            ],
        ],

        'stripe' => [
            'label' => 'Stripe (Export)',
            'currencies' => ['USD', 'EUR', 'GBP', 'AED', 'SGD', 'AUD', 'CAD', 'INR'],
            'export' => true,
            'flow' => 'redirect',
            'fields' => [
                'publishable_key' => ['label' => 'Publishable Key', 'secret' => false, 'required' => true],
                'secret_key' => ['label' => 'Secret Key', 'secret' => true, 'required' => true],
                'webhook_secret' => ['label' => 'Webhook Signing Secret', 'secret' => true, 'required' => false],
            ],
            'endpoints' => [
                'test' => 'https://api.stripe.com/v1',
                'live' => 'https://api.stripe.com/v1',
            ],
        ],

    ],
];
