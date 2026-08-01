<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The catalogue of gateways the SaaS operator can collect subscription money
 * through (Volume 11 §Billing → Payment Gateways).
 *
 * This is the *platform's* merchant list, and it is deliberately separate from
 * config('payments.gateways') — the tenant-side catalogue behind SRS §16, where a
 * workspace collects from its own customers. The two overlap in brand names and
 * in nothing else: different merchant accounts, different money, different
 * lifecycle.
 *
 * Only `razorpay` is wired end-to-end today (recurring subscriptions +
 * webhook). The rest are configurable and marked `recurring => false`: an
 * operator can store credentials and switch one on, and the panel says plainly
 * that collection through it is manual until a driver exists. That is honest
 * about the state of the integration rather than presenting a dead option.
 */
final class PlatformGateways
{
    /**
     * key => [label, credential fields, capabilities]
     *
     * A credential field marked `secret` is write-only over the API: it is never
     * returned to the browser, and submitting it blank leaves the stored value
     * alone (so an operator can edit the mode without re-typing the secret).
     *
     * @var array<string, array{
     *     label: string,
     *     docs: string,
     *     recurring: bool,
     *     fields: array<string, array{label: string, secret: bool, required: bool, hint?: string}>
     * }>
     */
    public const CATALOGUE = [
        'razorpay' => [
            'label' => 'Razorpay',
            'docs' => 'https://dashboard.razorpay.com/app/keys',
            'recurring' => true,
            'fields' => [
                'key_id' => ['label' => 'Key ID', 'secret' => false, 'required' => true, 'hint' => 'rzp_test_… / rzp_live_…'],
                'key_secret' => ['label' => 'Key Secret', 'secret' => true, 'required' => true],
                'webhook_secret' => ['label' => 'Webhook Secret', 'secret' => true, 'required' => false, 'hint' => 'Set the same value on the Razorpay webhook'],
            ],
        ],
        'stripe' => [
            'label' => 'Stripe',
            'docs' => 'https://dashboard.stripe.com/apikeys',
            'recurring' => false,
            'fields' => [
                'key_id' => ['label' => 'Publishable Key', 'secret' => false, 'required' => true, 'hint' => 'pk_test_… / pk_live_…'],
                'key_secret' => ['label' => 'Secret Key', 'secret' => true, 'required' => true, 'hint' => 'sk_test_… / sk_live_…'],
                'webhook_secret' => ['label' => 'Webhook Signing Secret', 'secret' => true, 'required' => false, 'hint' => 'whsec_…'],
            ],
        ],
        'payu' => [
            'label' => 'PayU',
            'docs' => 'https://onboarding.payu.in/app/account/dashboard',
            'recurring' => false,
            'fields' => [
                'key_id' => ['label' => 'Merchant Key', 'secret' => false, 'required' => true],
                'key_secret' => ['label' => 'Merchant Salt', 'secret' => true, 'required' => true],
            ],
        ],
        'paytm' => [
            'label' => 'Paytm',
            'docs' => 'https://dashboard.paytm.com/next/apikeys',
            'recurring' => false,
            'fields' => [
                'key_id' => ['label' => 'Merchant ID (MID)', 'secret' => false, 'required' => true],
                'key_secret' => ['label' => 'Merchant Key', 'secret' => true, 'required' => true],
                'website' => ['label' => 'Website', 'secret' => false, 'required' => false, 'hint' => 'WEBSTAGING / DEFAULT'],
            ],
        ],
        'cashfree' => [
            'label' => 'Cashfree',
            'docs' => 'https://merchant.cashfree.com/merchants/pg/developers',
            'recurring' => false,
            'fields' => [
                'key_id' => ['label' => 'App ID', 'secret' => false, 'required' => true],
                'key_secret' => ['label' => 'Secret Key', 'secret' => true, 'required' => true],
            ],
        ],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::CATALOGUE);
    }

    public static function supports(string $gateway): bool
    {
        return array_key_exists($gateway, self::CATALOGUE);
    }

    public static function label(string $gateway): string
    {
        return self::CATALOGUE[$gateway]['label'] ?? ucfirst($gateway);
    }

    /** @return array<string, array{label: string, secret: bool, required: bool, hint?: string}> */
    public static function fields(string $gateway): array
    {
        return self::CATALOGUE[$gateway]['fields'] ?? [];
    }

    /** Field keys that must never be echoed back to the browser. */
    public static function secretFields(string $gateway): array
    {
        return array_keys(array_filter(
            self::fields($gateway),
            static fn (array $field) => $field['secret'],
        ));
    }

    /** True when the gateway has a working recurring-billing driver behind it. */
    public static function supportsRecurring(string $gateway): bool
    {
        return (bool) (self::CATALOGUE[$gateway]['recurring'] ?? false);
    }

    /**
     * The catalogue as a list, ready for the panel's gateway cards.
     *
     * @return list<array<string, mixed>>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::CATALOGUE as $key => $meta) {
            $out[] = [
                'gateway' => $key,
                'label' => $meta['label'],
                'docs' => $meta['docs'],
                'recurring' => $meta['recurring'],
                'fields' => collect($meta['fields'])
                    ->map(fn (array $f, string $name) => ['name' => $name] + $f)
                    ->values()
                    ->all(),
            ];
        }

        return $out;
    }
}
