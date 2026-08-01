<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\PlatformSetting;

/**
 * The SaaS operator's own billing identity and tax defaults — the seller side of
 * every subscription invoice (Volume 11 §Billing → GST).
 *
 * Two layers, panel over config: `config('saas.billing')` ships the defaults an
 * install boots with, and whatever the operator saved in the platform panel
 * (stored in `platform_settings`) wins over them. That way a fresh deployment is
 * usable from .env alone, and changing the GSTIN later is an edit in the UI rather
 * than a deploy.
 *
 * Nothing here is read at invoice-render time: {@see \App\Services\Billing\SubscriptionBillingService}
 * snapshots these values onto the invoice row when it is raised, so correcting a
 * typo in the GSTIN tomorrow cannot silently rewrite an invoice already issued.
 */
final class PlatformBillingProfile
{
    /** platform_settings key holding the operator-edited overrides. */
    public const SETTING_KEY = 'billing_profile';

    /**
     * The effective profile: config defaults with the panel's overrides on top.
     *
     * @return array{
     *     legal_name: ?string, address: ?string, gstin: ?string, state_code: ?string,
     *     pan: ?string, email: ?string, phone: ?string,
     *     tax_rate: float, sac_code: ?string, prices_include_tax: bool,
     *     invoice_prefix: string, invoice_terms: ?string
     * }
     */
    public static function all(): array
    {
        $stored = (array) PlatformSetting::get(self::SETTING_KEY, []);

        $config = [
            'legal_name' => config('saas.billing.seller.legal_name'),
            'address' => config('saas.billing.seller.address'),
            'gstin' => config('saas.billing.seller.gstin'),
            'state_code' => config('saas.billing.seller.state_code'),
            'pan' => config('saas.billing.seller.pan'),
            'email' => config('saas.billing.seller.email') ?: config('mail.from.address'),
            'phone' => config('saas.billing.seller.phone'),
            'tax_rate' => (float) config('saas.billing.tax.rate', 18),
            'sac_code' => config('saas.billing.tax.sac_code'),
            'prices_include_tax' => (bool) config('saas.billing.tax.prices_include_tax', false),
            'invoice_prefix' => (string) config('saas.billing.invoice.prefix', 'INV'),
            'invoice_terms' => config('saas.billing.invoice.terms'),
        ];

        $merged = $config;
        foreach ($config as $key => $default) {
            if (! array_key_exists($key, $stored)) {
                continue;
            }

            $value = $stored[$key];
            // A blank string in the panel means "unset", so the config default
            // stays in play rather than the invoice printing an empty GSTIN.
            // Booleans and the tax rate are exempt: false and 0 are real answers.
            if (is_bool($default) || is_float($default)) {
                $merged[$key] = is_bool($default) ? (bool) $value : (float) $value;

                continue;
            }

            $merged[$key] = ($value === null || trim((string) $value) === '') ? $default : (string) $value;
        }

        // The GSTIN encodes the state of registration; only fall back to the
        // stored/config code when there is no GSTIN to read it from.
        $merged['state_code'] = self::resolveStateCode($merged['gstin'], $merged['state_code']);
        $merged['invoice_prefix'] = strtoupper(trim($merged['invoice_prefix'])) ?: 'INV';

        return $merged;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    /** The seller's GST state code, or null when we are not GST-registered. */
    public static function stateCode(): ?string
    {
        return self::all()['state_code'];
    }

    public static function gstin(): ?string
    {
        return self::all()['gstin'];
    }

    /**
     * The rate to charge, or 0 when this deployment does not bill GST.
     *
     * No seller GSTIN means no tax invoice: an unregistered supplier cannot
     * collect GST, so the rate is forced to zero regardless of what is configured.
     */
    public static function effectiveTaxRate(): float
    {
        $profile = self::all();

        return $profile['gstin'] ? max(0.0, (float) $profile['tax_rate']) : 0.0;
    }

    /** True when the operator is GST-registered and charging tax. */
    public static function chargesTax(): bool
    {
        return self::effectiveTaxRate() > 0;
    }

    /**
     * Persist operator edits. Only the keys above are stored; anything else is
     * dropped rather than silently kept in a settings blob nobody reads.
     *
     * @param  array<string, mixed>  $values
     */
    public static function save(array $values): void
    {
        $allowed = [
            'legal_name', 'address', 'gstin', 'state_code', 'pan', 'email', 'phone',
            'tax_rate', 'sac_code', 'prices_include_tax', 'invoice_prefix', 'invoice_terms',
        ];

        $stored = (array) PlatformSetting::get(self::SETTING_KEY, []);

        foreach ($values as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                continue;
            }

            $stored[$key] = match ($key) {
                'gstin' => $value === null ? null : strtoupper(trim((string) $value)),
                'prices_include_tax' => (bool) $value,
                'tax_rate' => round((float) $value, 2),
                default => $value,
            };
        }

        PlatformSetting::put(self::SETTING_KEY, $stored);
    }

    /**
     * Prefer the state encoded in the GSTIN over any hand-entered code — they can
     * only disagree by mistake, and the GSTIN is the one the GST portal believes.
     */
    private static function resolveStateCode(?string $gstin, ?string $fallback): ?string
    {
        $fromGstin = $gstin ? substr(trim($gstin), 0, 2) : null;

        if ($fromGstin && GstStateCodes::name($fromGstin)) {
            return $fromGstin;
        }

        $fallback = $fallback ? substr(trim($fallback), 0, 2) : null;

        return $fallback && GstStateCodes::name($fallback) ? $fallback : null;
    }
}
