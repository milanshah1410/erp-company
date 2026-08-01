<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Domain;
use App\Models\Setting;
use App\Models\Tenant;
use Illuminate\Support\Str;

/**
 * White label (Volume 11 §"White Label"): the brand a given workspace wears —
 * logo, name, theme colours — resolved from the host that asked.
 *
 * Storage: the values live in the tenant's own `settings` rows (Setting::BRAND_KEYS),
 * which is also where its logo and SMTP account already were, so the SaaS-owner
 * panel and the tenant's own Settings screen read one source of truth. The
 * platform's brand (config('saas.branding')) is the fallback for every field, so
 * a workspace that was never white labelled is unaffected.
 *
 * Resolution: a request arrives on some host — a tenant's custom domain, its
 * `{sub}.{root}` workspace address, or the platform host. Only the first two
 * carry a tenant; the platform host gets platform defaults. Note the app still
 * resolves *tenancy* from the authenticated user (see App\Support\Subdomain) —
 * this lookup exists so an unauthenticated screen (sign-in, provisioning, the
 * suspended page) can wear the right brand before anyone has logged in.
 */
final class Branding
{
    /**
     * The platform's own brand: what the signup journey, the operator panel and
     * any unbranded workspace render.
     *
     * @return array<string, mixed>
     */
    public static function platform(): array
    {
        $config = (array) config('saas.branding', []);

        return [
            'tenant_id' => null,
            'company_name' => null,
            'app_name' => (string) ($config['app_name'] ?? 'Kosha'),
            'tagline' => (string) ($config['tagline'] ?? ''),
            'logo_url' => $config['logo_url'] ?: null,
            'primary_color' => (string) ($config['primary_color'] ?? '#e8a33d'),
            'accent_color' => (string) ($config['accent_color'] ?? '#2c4a5e'),
            'dark_default' => false,
            'powered_by' => true,
            'white_labelled' => false,
        ];
    }

    /**
     * One tenant's brand, every unset field falling back to the platform's.
     *
     * Reads inside the tenant context because settings are tenant-scoped (the
     * scope fails closed without one), so this is safe to call from a central
     * route with no tenancy initialised.
     *
     * @return array<string, mixed>
     */
    public static function forTenant(Tenant $tenant): array
    {
        $platform = self::platform();

        $stored = app(TenantContext::class)->runWith(
            $tenant->id,
            // allWithDefaults() reads through Setting's own per-tenant cache, so
            // the branding lookup on an unauthenticated page costs no query in
            // the common case.
            fn (): array => array_intersect_key(Setting::allWithDefaults(), array_flip(Setting::BRAND_KEYS)),
        );

        $value = static fn (string $key): ?string => is_string($stored[$key] ?? null) && trim($stored[$key]) !== ''
            ? trim($stored[$key])
            : null;

        $logo = $value('company_logo_url');
        $appName = $value('brand_app_name');
        $primary = self::color($value('brand_primary_color'));
        $accent = self::color($value('brand_accent_color'));

        return [
            'tenant_id' => $tenant->id,
            'company_name' => $tenant->company_name,
            'app_name' => $appName ?? $tenant->company_name ?? $platform['app_name'],
            'tagline' => $value('brand_tagline') ?? $platform['tagline'],
            'logo_url' => $logo ?? $platform['logo_url'],
            'primary_color' => $primary ?? $platform['primary_color'],
            'accent_color' => $accent ?? $platform['accent_color'],
            'dark_default' => ($stored['brand_dark_default'] ?? '0') === '1',
            'powered_by' => ($stored['brand_powered_by'] ?? '1') === '1',
            // Whether anything was actually overridden — the SPA uses this to
            // decide between the tenant's logo and the platform lockup.
            'white_labelled' => $logo !== null || $appName !== null || $primary !== null || $accent !== null,
        ];
    }

    /**
     * The brand for whoever is asking, by host. Falls back to the platform brand
     * for the platform's own hosts and for anything unrecognised.
     *
     * @return array<string, mixed>
     */
    public static function forHost(?string $host): array
    {
        $tenant = self::tenantForHost($host);

        return $tenant ? self::forTenant($tenant) : self::platform();
    }

    /**
     * The tenant a host belongs to: an exact match on a recorded domain first
     * (that is what a custom domain is), then the `{sub}.{root}` workspace
     * address. Null for the platform host or an unknown one.
     */
    public static function tenantForHost(?string $host): ?Tenant
    {
        $host = self::normalizeHost($host);
        if ($host === '') {
            return null;
        }

        $domain = Domain::where('domain', $host)->first();
        if ($domain) {
            return $domain->tenant;
        }

        $root = Str::lower(Subdomain::rootDomain());
        if ($root !== '' && str_ends_with($host, '.'.$root)) {
            $sub = Subdomain::normalize(substr($host, 0, -strlen('.'.$root)));

            return $sub === '' ? null : Tenant::where('subdomain', $sub)->first();
        }

        return null;
    }

    /** Lowercase, drop the port and a leading `www.`. */
    private static function normalizeHost(?string $host): string
    {
        $host = Str::lower(trim((string) $host));
        $host = (string) preg_replace('/:\d+$/', '', $host);
        $host = ltrim($host, '.');

        return Str::startsWith($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * A stored colour, only if it is a hex literal. Anything else is dropped
     * rather than passed through: these values are interpolated into a style
     * attribute in the browser, so the shape is the guard.
     */
    private static function color(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) === 1
            ? Str::lower($value)
            : null;
    }

    /** The validation rule the panel and this class agree a colour must match. */
    public const COLOR_RULE = 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/';
}
