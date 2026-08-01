<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * Per-tenant file storage on a single shared bucket/disk. Every tenant's files
 * live under their own folder prefix — `tenant-{id}/...` — mirroring the shared-
 * database, row-level `tenant_id` isolation the rest of the app uses (see
 * TenantContext). Call sites never write the prefix themselves: the disk built
 * here is already rooted inside the active tenant's folder, so escaping the
 * tenant's namespace is not expressible.
 *
 * The underlying disk is chosen by config('filesystems.tenant_disk') — `s3` in
 * production (one bucket, one folder per tenant), `local` in dev. Files are
 * private; they are exposed only through short-lived signed URLs (temporaryUrl).
 */
final class TenantStorage
{
    /** Default lifetime (minutes) of a signed file URL. */
    public const URL_TTL_MINUTES = 30;

    /**
     * The active tenant's private disk, rooted at `tenant-{id}` inside the
     * shared parent disk. Built fresh each call so it always reflects the
     * current TenantContext — safe across queue jobs on a long-lived worker,
     * where a cached named disk would leak one tenant's root into the next job.
     */
    public static function disk(): Filesystem
    {
        return Storage::build(self::tenantConfig(self::tenantId()));
    }

    /**
     * A short-lived signed URL for a stored file, or null when $path is empty.
     *
     * On S3 this is a native pre-signed URL served straight from the bucket (no
     * app round-trip). On the local dev disk — which has no native temporary
     * URLs — it falls back to a signed, expiring route that streams the file
     * (routes/api.php → 'tenant.file'), preserving the same semantics.
     */
    public static function temporaryUrl(?string $path, int $minutes = self::URL_TTL_MINUTES): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (self::parentDiskName() === 's3') {
            return self::disk()->temporaryUrl($path, now()->addMinutes($minutes));
        }

        return URL::temporarySignedRoute('tenant.file', now()->addMinutes($minutes), [
            'tenant' => self::tenantId(),
            'path' => $path,
        ]);
    }

    /**
     * The same private disk, for an explicit tenant id rather than the active
     * context. The platform panel meters storage per tenant (App\Support\
     * UsageMeter) with no tenant context to read, so it names the tenant instead.
     */
    public static function diskForTenant(int $tenantId): Filesystem
    {
        return Storage::build(self::tenantConfig($tenantId));
    }

    /**
     * Build the tenant-scoped disk config for an explicit tenant id. Used by the
     * local streaming route, which learns the tenant from the signed URL rather
     * than from the request's TenantContext.
     *
     * @return array<string, mixed>
     */
    public static function tenantConfig(int $tenantId): array
    {
        $parent = self::parentDiskName();
        $config = config("filesystems.disks.{$parent}");

        if (! is_array($config)) {
            throw new RuntimeException("Tenant parent disk [{$parent}] is not configured.");
        }

        $base = rtrim((string) ($config['root'] ?? ''), '/\\');
        $config['root'] = ($base === '' ? '' : $base.'/')."tenant-{$tenantId}";

        return $config;
    }

    private static function parentDiskName(): string
    {
        return (string) config('filesystems.tenant_disk', 'local');
    }

    private static function tenantId(): int
    {
        $id = app(TenantContext::class)->id();

        if ($id === null) {
            throw new RuntimeException('TenantStorage requires an active tenant context.');
        }

        return $id;
    }
}
