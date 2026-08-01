<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Domain;
use App\Models\PendingRegistration;
use App\Models\Tenant;
use Illuminate\Support\Str;

/**
 * Workspace-address rules for signup (§5 Subdomain Provisioning).
 *
 * A workspace claims `{subdomain}.{root domain}` at signup: the address is
 * validated, reserved-word checked and uniquely recorded (tenants.subdomain +
 * a primary `domains` row) so it can never be handed to a second tenant.
 *
 * Note this is *identity*, not routing. Tenants are still resolved from the
 * authenticated user on a single host (see config/saas.php) — pointing wildcard
 * DNS at the app and switching resolution over is an infra step documented in
 * the README, and the reservation made here is what makes it possible later.
 */
final class Subdomain
{
    public const MIN = 3;

    public const MAX = 30;

    /**
     * Addresses the platform keeps for itself. A tenant claiming `api` or
     * `admin` would shadow a platform host the moment wildcard DNS goes live,
     * so they are blocked at signup rather than reclaimed later.
     *
     * @var list<string>
     */
    public const RESERVED = [
        'www', 'api', 'admin', 'app', 'mail', 'ftp', 'staging', 'billing', 'support',
        'blog', 'cdn', 'dashboard', 'dev', 'docs', 'help', 'login', 'mx', 'ns', 'ns1',
        'ns2', 'pay', 'platform', 'portal', 'root', 'secure', 'shop', 'smtp', 'sso',
        'status', 'store', 'test', 'webmail', 'assets', 'static', 'internal', 'central',
    ];

    /** The host new workspaces live under, e.g. `yourapp.com`. */
    public static function rootDomain(): string
    {
        return (string) config('saas.root_domain', 'yourapp.com');
    }

    /** Fully-qualified workspace address, e.g. `acme.yourapp.com`. */
    public static function fqdn(string $subdomain): string
    {
        return static::normalize($subdomain).'.'.static::rootDomain();
    }

    /** Browsable workspace URL, e.g. `https://acme.yourapp.com`. */
    public static function url(string $subdomain): string
    {
        return (string) config('saas.workspace_scheme', 'https').'://'.static::fqdn($subdomain);
    }

    /** Lowercase, trim, and drop anything that is not a-z, 0-9 or a hyphen. */
    public static function normalize(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9-]/', '', Str::lower(trim($value))) ?? '', '-');
    }

    /**
     * Turn a company name into a first-guess address ("Acme Traders Pvt Ltd" →
     * "acme-traders"). Suffixes buyers never type are dropped, and the result is
     * padded/truncated into the length window so the suggestion is always valid.
     */
    public static function suggest(string $companyName): string
    {
        $slug = Str::slug($companyName);

        // Company-form suffixes carry no meaning in a URL. Stripped repeatedly
        // so "…-pvt-ltd" loses both halves, not just the last one — but never
        // down to nothing ("Ltd Company" keeps something to work with).
        $suffixes = '/-(pvt|private|ltd|limited|llp|inc|incorporated|corp|corporation|co|company|enterprises|enterprise|traders|sons)$/i';
        while (preg_match($suffixes, $slug)) {
            $stripped = (string) preg_replace($suffixes, '', $slug);
            if ($stripped === '') {
                break;
            }
            $slug = $stripped;
        }

        $slug = static::normalize($slug);

        if ($slug === '') {
            return '';
        }

        if (strlen($slug) > self::MAX) {
            $slug = trim(substr($slug, 0, self::MAX), '-');
        }

        // Too short to be legal on its own — a numeric tail beats rejecting it.
        if (strlen($slug) < self::MIN) {
            $slug = str_pad($slug, self::MIN, '0');
        }

        return $slug;
    }

    /**
     * Why this address can't be used, or null when it is free.
     * The message is written to be shown verbatim under the field.
     */
    public static function error(string $value): ?string
    {
        $sub = static::normalize($value);

        if ($sub === '') {
            return 'Choose a workspace address.';
        }

        if (strlen($sub) < self::MIN || strlen($sub) > self::MAX) {
            return 'Use between '.self::MIN.' and '.self::MAX.' characters.';
        }

        if (! preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $sub)) {
            return 'Use lowercase letters, numbers and hyphens — not starting or ending with a hyphen.';
        }

        if (str_contains($sub, '--')) {
            return 'Hyphens can’t be doubled up.';
        }

        if (in_array($sub, self::RESERVED, true)) {
            return 'That address is reserved by the platform. Try another.';
        }

        if (static::isTaken($sub)) {
            return 'That address is already taken.';
        }

        return null;
    }

    /** Claimed by a live tenant, a recorded domain, or a signup in progress. */
    public static function isTaken(string $subdomain): bool
    {
        $sub = static::normalize($subdomain);

        if (Tenant::where('subdomain', $sub)->exists()) {
            return true;
        }

        if (Domain::where('domain', static::fqdn($sub))->exists()) {
            return true;
        }

        return PendingRegistration::where('subdomain', $sub)
            ->where('status', '!=', 'failed')
            ->exists();
    }

    /**
     * A free address near the one asked for: `acme`, then `acme-1`, `acme-2`…
     * Used to offer a one-click alternative instead of a dead end.
     *
     * @return list<string>
     */
    public static function alternatives(string $value, int $limit = 3): array
    {
        $base = static::normalize($value);
        if ($base === '') {
            return [];
        }

        // Leave room for the "-NN" tail without breaking the length ceiling.
        $base = trim(substr($base, 0, self::MAX - 3), '-');
        $out = [];

        for ($i = 1; $i <= 25 && count($out) < $limit; $i++) {
            $candidate = $base.'-'.$i;
            if (static::error($candidate) === null) {
                $out[] = $candidate;
            }
        }

        return $out;
    }
}
