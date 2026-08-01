<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\Branding;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The brand a visitor should see, before they have logged in (Volume 11
 * §"White Label"). Public by necessity: the sign-in page, the provisioning
 * screen and the suspended page all have to wear the right logo and colours
 * with nobody authenticated.
 *
 * The response carries only what a browser is about to render anyway — name,
 * logo, two colours — and no tenant business data, which is what makes the
 * `host` override safe: the SPA still runs on a single host today (tenancy is
 * resolved from the authenticated user), so in local development it has to be
 * able to ask "what would acme.yourapp.com look like".
 */
class BrandingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $host = (string) ($request->query('host') ?: $request->getHost());

        return response()->json(['data' => Branding::forHost($host)]);
    }

    /**
     * The signed-in workspace's own brand (GET /api/my-branding, tenant routes).
     *
     * Host resolution above cannot help a deployment that serves every tenant
     * from one host — which is this one, since tenancy is resolved from the
     * authenticated user. Once there is a session, the tenant is known for
     * certain, so this is the authoritative answer and the SPA re-applies it
     * over whatever the host lookup guessed.
     */
    public function mine(): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $tenant = $tenantId ? Tenant::find($tenantId) : null;

        return response()->json([
            'data' => $tenant ? Branding::forTenant($tenant) : Branding::platform(),
        ]);
    }
}
