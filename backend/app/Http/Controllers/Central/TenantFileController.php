<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Support\TenantStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Local-dev fallback for serving a tenant's private files. In production files
 * are served by native pre-signed S3 URLs (TenantStorage::temporaryUrl) and
 * this route is never hit; on the local disk — which cannot mint temporary URLs
 * — TenantStorage points its signed URLs here instead. The 'signed' middleware
 * guarantees the {tenant}+{path}+expiry were not tampered with, so the signature
 * itself is the capability: no auth or TenantContext is required (and none is
 * available here — this is a central route with no tenancy initialized).
 */
class TenantFileController extends Controller
{
    public function show(Request $request, int $tenant, string $path): StreamedResponse
    {
        $disk = Storage::build(TenantStorage::tenantConfig($tenant));

        abort_unless($disk->exists($path), 404);

        return $disk->response($path);
    }
}
