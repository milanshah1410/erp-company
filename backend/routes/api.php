<?php

declare(strict_types=1);

use App\Http\Controllers\Central\AnalyticsController;
use App\Http\Controllers\Central\AuthController as CentralAuthController;
use App\Http\Controllers\Central\BillingCouponController;
use App\Http\Controllers\Central\BillingGatewayController;
use App\Http\Controllers\Central\BillingInvoiceController;
use App\Http\Controllers\Central\BillingPlanController;
use App\Http\Controllers\Central\BillingRenewalController;
use App\Http\Controllers\Central\BillingRevenueController;
use App\Http\Controllers\Central\BillingSubscriptionController;
use App\Http\Controllers\Central\BillingTaxController;
use App\Http\Controllers\Central\BrandingController;
use App\Http\Controllers\Central\DashboardController as CentralDashboardController;
use App\Http\Controllers\Central\IndustryTemplateController;
use App\Http\Controllers\Central\InsightsController;
use App\Http\Controllers\Central\MarketplaceAssignmentController;
use App\Http\Controllers\Central\MarketplaceController;
use App\Http\Controllers\Central\MarketplacePackController;
use App\Http\Controllers\Central\MenuCatalogueController;
use App\Http\Controllers\Central\OperatorController;
use App\Http\Controllers\Central\Ops\BackupController as OpsBackupController;
use App\Http\Controllers\Central\Ops\FeatureFlagController;
use App\Http\Controllers\Central\Ops\LogController as OpsLogController;
use App\Http\Controllers\Central\Ops\QueueController as OpsQueueController;
use App\Http\Controllers\Central\Ops\SystemHealthController;
use App\Http\Controllers\Central\Ops\TenantToolController;
use App\Http\Controllers\Central\PaymentWebhookController;
use App\Http\Controllers\Central\PlanController;
use App\Http\Controllers\Central\PublicFeedbackController;
use App\Http\Controllers\Central\PublicPaymentController;
use App\Http\Controllers\Central\RazorpayWebhookController;
use App\Http\Controllers\Central\RegistrationController;
use App\Http\Controllers\Central\RoleController as CentralRoleController;
use App\Http\Controllers\Central\ShopMenuTemplateController;
use App\Http\Controllers\Central\Support\TenantHealthController as SupportTenantHealthController;
use App\Http\Controllers\Central\Support\TicketController as SupportTicketController;
use App\Http\Controllers\Central\TenantAccessController;
use App\Http\Controllers\Central\TenantBrandingController;
use App\Http\Controllers\Central\TenantController as CentralTenantController;
use App\Http\Controllers\Central\TenantFileController;
use App\Http\Controllers\Central\TenantMenuBuilderController;
use App\Http\Controllers\Central\TenantUsageController;
use App\Modules\Industry\Support\IndustryRegistry;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Central (landlord) API routes  —  <APP_URL>/api/...
|--------------------------------------------------------------------------
|
| Served on the central domain(s) (localhost / 127.0.0.1 / yourapp.com). These
| power the public marketing/signup surface and platform webhooks. They run
| with NO tenancy initialized, so they see the central database.
|
| All business + tenant-auth routes live in routes/tenant.php behind the
| subdomain-tenancy middleware. URIs here are deliberately disjoint from the
| tenant routes so the two route files never collide.
|
*/

// Public pricing / plan catalogue (for the signup + upgrade pages). Each plan
// carries `feature_limits.modules` — the module keys that plan may switch on.
Route::get('/plans', [PlanController::class, 'index']);

// The module catalogue the onboarding wizard renders in step 3.
Route::get('/modules', [PlanController::class, 'modules']);

// Shop-type presets ("Grocery", "Garment", …) that pre-tick that step for a trade.
Route::get('/shop-profiles', [PlanController::class, 'shopProfiles']);

// The industry catalogue the wizard's step-2 picker groups those trades under
// (Volume 11 §"Industry Templates"). Operator-maintained, so it is read from the
// DB rather than a constant.
Route::get('/industries', [PlanController::class, 'industries']);

// Self-serve tenant registration + email verification + provisioning status.
Route::get('/check-email', [RegistrationController::class, 'checkEmail']);
// Live workspace-address availability (§5) — also suggests one from ?company=.
Route::get('/check-subdomain', [RegistrationController::class, 'checkSubdomain']);
// Live promo-code check for the pricing/signup form. Reveals only whether the
// code applies to the named plan, and by how much.
Route::get('/check-coupon', [RegistrationController::class, 'checkCoupon']);
Route::post('/register', [RegistrationController::class, 'register']);
Route::post('/register/logo', [RegistrationController::class, 'uploadLogo']);
Route::post('/verify-email', [RegistrationController::class, 'verifyEmail']);
Route::post('/resend-verification', [RegistrationController::class, 'resendVerification']);
Route::get('/provisioning-status/{registration}', [RegistrationController::class, 'provisioningStatus']);

// The brand to render for whoever is asking (Volume 11 §"White Label"), keyed
// off the request host — or ?host= while the SPA still runs on a single host.
// Public because the screens that need it (sign-in, provisioning, suspended)
// come before authentication.
Route::get('/branding', [BrandingController::class, 'show']);

// Razorpay recurring-billing webhook (signature-verified inside the controller).
Route::post('/razorpay/webhook', [RazorpayWebhookController::class, 'handle']);

/*
| Customer payment collection (SRS §16). The hosted pay page + gateway
| callbacks/webhooks are public: the tenant is derived from the payment link
| token / a signed webhook, not from an authenticated user.
*/
Route::get('/pay/{token}', [PublicPaymentController::class, 'show']);
Route::post('/pay/{token}/start', [PublicPaymentController::class, 'start']);
Route::match(['get', 'post'], '/pay/{token}/callback', [PublicPaymentController::class, 'callback']);
Route::post('/webhooks/payments/{gateway}', [PaymentWebhookController::class, 'handle']);

/*
| Customer feedback / CSAT (retail base §6). Public for the same reason the pay
| page is: the person answering is a shopper holding a link, not a user of the
| workspace. The tenant comes from the token, the token reaches exactly one row,
| and an answered row refuses a second answer — see PublicFeedbackController.
*/
Route::get('/feedback/{token}', [PublicFeedbackController::class, 'show']);
Route::post('/feedback/{token}', [PublicFeedbackController::class, 'submit']);

/*
| Public routes contributed by industry plugins (app/Modules/Industry) — e.g. the
| Food & Beverage QR menu at /menu/{token}. Each resolves its tenant from the
| token in the URL, never from an authenticated user, and each is read-only.
| Kept in the plugin's own routes-public.php, separate from its tenant routes, so
| that what is exposed without auth is always one short file to review.
*/
foreach (IndustryRegistry::publicRouteFiles() as $industryPublicRoutes) {
    require $industryPublicRoutes;
}

/*
| Local-dev signed streaming of tenant files (see App\Support\TenantStorage).
| The 'signed' middleware is the whole authorization: the tenant id, path and
| expiry are baked into the signature. In production, tenant files are served by
| native pre-signed S3 URLs and this route is not hit.
*/
Route::get('/tenant-files/{tenant}/{path}', [TenantFileController::class, 'show'])
    ->where('path', '.*')
    ->middleware('signed')
    ->name('tenant.file');

/*
|--------------------------------------------------------------------------
| SaaS-owner platform panel  —  <APP_URL>/api/central/...
|--------------------------------------------------------------------------
|
| Level-1 (platform operator) RBAC. Operators authenticate on the `central`
| guard; `central.context` pins Spatie's team scope to NULL (where the central
| roles live) and blocks deactivated accounts. Every business endpoint is gated
| by a platform permission on that guard (permission:<name>,central).
|
*/
Route::prefix('central')->group(function () {
    Route::post('/login', [CentralAuthController::class, 'login']);

    Route::middleware(['auth:central', 'central.context'])->group(function () {
        Route::get('/me', [CentralAuthController::class, 'me']);
        Route::post('/logout', [CentralAuthController::class, 'logout']);

        // The platform dashboard (Volume 11 §"Dashboard"): tenant base, MRR/ARR,
        // revenue, payments and API/storage/AI consumption in one payload. Held
        // on `platform.dashboard.view` — every role has it — with the money and
        // usage blocks withheld per-operator inside the controller.
        Route::get('/dashboard', [CentralDashboardController::class, 'summary'])->middleware('permission:platform.dashboard.view,central');

        // Platform operators + their Level-1 roles.
        Route::get('/operators', [OperatorController::class, 'index'])->middleware('permission:operators.view,central');
        Route::post('/operators', [OperatorController::class, 'store'])->middleware('permission:operators.create,central');
        Route::get('/operators/{operator}', [OperatorController::class, 'show'])->middleware('permission:operators.view,central');
        Route::match(['put', 'patch'], '/operators/{operator}', [OperatorController::class, 'update'])->middleware('permission:operators.edit,central');
        Route::delete('/operators/{operator}', [OperatorController::class, 'destroy'])->middleware('permission:operators.delete,central');

        // Tenant workspaces: list/create, suspend/reactivate, impersonate.
        Route::get('/tenants', [CentralTenantController::class, 'index'])->middleware('permission:tenants.view,central');
        Route::post('/tenants', [CentralTenantController::class, 'store'])->middleware('permission:tenants.create,central');
        Route::get('/tenants/provisioning/{registration}', [CentralTenantController::class, 'provisioningStatus'])->middleware('permission:tenants.create,central');
        Route::post('/tenants/{tenant}/suspend', [CentralTenantController::class, 'suspend'])->middleware('permission:tenants.suspend,central');
        Route::post('/tenants/{tenant}/activate', [CentralTenantController::class, 'activate'])->middleware('permission:tenants.suspend,central');
        Route::post('/tenants/{tenant}/impersonate', [CentralTenantController::class, 'impersonate'])->middleware('permission:tenants.impersonate,central');

        // Company profile (contact details + industry/business type) and the
        // tenant's resource limits: plan caps, per-tenant overrides and usage.
        // Declared after the fixed /tenants/provisioning/{registration} path so
        // "provisioning" can never be captured as a {tenant}.
        Route::get('/tenants/{tenant}', [CentralTenantController::class, 'show'])->middleware('permission:tenants.view,central');
        Route::match(['put', 'patch'], '/tenants/{tenant}', [CentralTenantController::class, 'update'])->middleware('permission:tenants.edit,central');
        Route::get('/tenants/{tenant}/limits', [CentralTenantController::class, 'limits'])->middleware('permission:tenants.view,central');
        Route::match(['put', 'patch'], '/tenants/{tenant}/limits', [CentralTenantController::class, 'updateLimits'])->middleware('permission:tenants.edit,central');

        // Industry templates — the vertical catalogue new signups pick from.
        Route::get('/industries', [IndustryTemplateController::class, 'index'])->middleware('permission:industries.view,central');
        Route::post('/industries', [IndustryTemplateController::class, 'store'])->middleware('permission:industries.create,central');
        Route::match(['put', 'patch'], '/industries/{industryTemplate}', [IndustryTemplateController::class, 'update'])->middleware('permission:industries.edit,central');
        Route::post('/industries/{industryTemplate}/toggle', [IndustryTemplateController::class, 'toggle'])->middleware('permission:industries.edit,central');
        Route::delete('/industries/{industryTemplate}', [IndustryTemplateController::class, 'destroy'])->middleware('permission:industries.delete,central');

        // The shared menu catalogue — every screen the product ships, switched on
        // or off at the source. The per-tenant twins of this live further down
        // (/tenants/{tenant}/access and /tenants/{tenant}/menu); this one decides
        // what a workspace is cloned from in the first place. /menus/bulk is
        // declared before the {menuItem} path so "bulk" is never read as an id.
        Route::get('/menus', [MenuCatalogueController::class, 'index'])->middleware('permission:menus.view,central');
        Route::post('/menus/bulk', [MenuCatalogueController::class, 'bulk'])->middleware('permission:menus.edit,central');
        Route::post('/menus/{menuItem}/toggle', [MenuCatalogueController::class, 'toggle'])->middleware('permission:menus.edit,central');

        // Shop-wise menu templates — the trade layer between the catalogue above
        // and a workspace's own menu below: what a *Grocery* signup opens on. The
        // {shop} segment is a trade key ('garment', 'grocery'), not an id, so it
        // is constrained to the key charset rather than left to swallow anything.
        // /shop-menus/{shop}/apply is the only route here that touches a live
        // workspace, and it is a separate call for exactly that reason.
        Route::get('/shop-menus', [ShopMenuTemplateController::class, 'index'])->middleware('permission:menus.view,central');

        Route::where(['shop' => '[a-z0-9_]+'])->group(function () {
            Route::get('/shop-menus/{shop}', [ShopMenuTemplateController::class, 'show'])->middleware('permission:menus.view,central');
            Route::match(['put', 'patch'], '/shop-menus/{shop}', [ShopMenuTemplateController::class, 'update'])->middleware('permission:menus.edit,central');
            Route::post('/shop-menus/{shop}/reset', [ShopMenuTemplateController::class, 'reset'])->middleware('permission:menus.edit,central');
            Route::post('/shop-menus/{shop}/apply', [ShopMenuTemplateController::class, 'apply'])->middleware('permission:menus.edit,central');
            Route::delete('/shop-menus/{shop}', [ShopMenuTemplateController::class, 'destroy'])->middleware('permission:menus.edit,central');
        });

        // Per-tenant entitlement: module switches + which menu screens (and so
        // which permissions) the workspace may use at all. Gated by the operator's
        // own can_manage_tenant_access flag instead of a role permission — see
        // App\Http\Middleware\EnsureTenantAccessManager.
        Route::get('/tenants/{tenant}/access', [TenantAccessController::class, 'show'])->middleware('central.tenant-access');
        Route::match(['put', 'patch'], '/tenants/{tenant}/access', [TenantAccessController::class, 'update'])->middleware('central.tenant-access');

        // Per-tenant menu builder: the same rows the Access panel switches off,
        // but reordered, re-nested (drag & drop, any depth) and renamed. Same
        // can_manage_tenant_access gate — re-parenting a screen is the same power
        // as revoking it. /menu/reset is declared first so "reset" is never
        // captured as part of the {tenant} segment above.
        Route::post('/tenants/{tenant}/menu/reset', [TenantMenuBuilderController::class, 'reset'])->middleware('central.tenant-access');
        Route::get('/tenants/{tenant}/menu', [TenantMenuBuilderController::class, 'show'])->middleware('central.tenant-access');
        Route::match(['put', 'patch'], '/tenants/{tenant}/menu', [TenantMenuBuilderController::class, 'update'])->middleware('central.tenant-access');

        // White label (Volume 11): the tenant's logo, custom domains, theme and
        // the SMTP account its mail goes out through. Its own permission
        // vocabulary — re-skinning and re-addressing a customer's workspace is a
        // different job from editing their company profile.
        Route::prefix('tenants/{tenant}/branding')->group(function () {
            Route::get('/', [TenantBrandingController::class, 'show'])->middleware('permission:white-label.view,central');
            Route::match(['put', 'patch'], '/', [TenantBrandingController::class, 'update'])->middleware('permission:white-label.edit,central');
            Route::post('/logo', [TenantBrandingController::class, 'uploadLogo'])->middleware('permission:white-label.edit,central');
            Route::delete('/logo', [TenantBrandingController::class, 'removeLogo'])->middleware('permission:white-label.edit,central');
            Route::post('/domains', [TenantBrandingController::class, 'addDomain'])->middleware('permission:white-label.edit,central');
            Route::delete('/domains/{domain}', [TenantBrandingController::class, 'removeDomain'])->middleware('permission:white-label.edit,central');
            Route::match(['put', 'patch'], '/smtp', [TenantBrandingController::class, 'updateSmtp'])->middleware('permission:white-label.edit,central');
            Route::post('/smtp/test', [TenantBrandingController::class, 'testSmtp'])->middleware('permission:white-label.edit,central');
        });

        // Billing & Subscriptions: plan catalogue admin, tenant subscriptions,
        // invoices/refunds and revenue analytics (SaaS-owner billing panel).
        Route::prefix('billing')->group(function () {
            Route::get('/plans', [BillingPlanController::class, 'index'])->middleware('permission:plans.view,central');
            Route::post('/plans', [BillingPlanController::class, 'store'])->middleware('permission:plans.create,central');
            Route::match(['put', 'patch'], '/plans/{plan}', [BillingPlanController::class, 'update'])->middleware('permission:plans.edit,central');
            Route::delete('/plans/{plan}', [BillingPlanController::class, 'destroy'])->middleware('permission:plans.delete,central');

            Route::get('/subscriptions', [BillingSubscriptionController::class, 'index'])->middleware('permission:subscriptions.view,central');
            Route::get('/subscriptions/{subscription}', [BillingSubscriptionController::class, 'show'])->middleware('permission:subscriptions.view,central');
            Route::match(['put', 'patch'], '/subscriptions/{subscription}', [BillingSubscriptionController::class, 'update'])->middleware('permission:subscriptions.edit,central');
            Route::post('/subscriptions/{subscription}/cancel', [BillingSubscriptionController::class, 'cancel'])->middleware('permission:subscriptions.cancel,central');
            Route::post('/subscriptions/{subscription}/record-payment', [BillingSubscriptionController::class, 'recordPayment'])->middleware('permission:subscriptions.edit,central');
            // What the next invoice will come to (price → coupon → GST), and the
            // recurring coupon pinned to the subscription.
            Route::get('/subscriptions/{subscription}/quote', [BillingSubscriptionController::class, 'quote'])->middleware('permission:subscriptions.view,central');
            Route::post('/subscriptions/{subscription}/coupon', [BillingSubscriptionController::class, 'applyCoupon'])->middleware('permission:subscriptions.edit,central');

            Route::get('/invoices', [BillingInvoiceController::class, 'index'])->middleware('permission:invoices.view,central');
            Route::post('/invoices/{invoice}/refund', [BillingInvoiceController::class, 'refund'])->middleware('permission:invoices.refund,central');

            Route::get('/revenue', [BillingRevenueController::class, 'summary'])->middleware('permission:revenue.view,central');

            // Promotional coupons against our own pricing (Volume 11 §Billing →
            // Coupons). Distinct from the tenant-side retail coupons a shop runs.
            Route::get('/coupons', [BillingCouponController::class, 'index'])->middleware('permission:coupons.view,central');
            Route::post('/coupons', [BillingCouponController::class, 'store'])->middleware('permission:coupons.create,central');
            Route::get('/coupons/{coupon}/redemptions', [BillingCouponController::class, 'redemptions'])->middleware('permission:coupons.view,central');
            Route::match(['put', 'patch'], '/coupons/{coupon}', [BillingCouponController::class, 'update'])->middleware('permission:coupons.edit,central');
            Route::post('/coupons/{coupon}/toggle', [BillingCouponController::class, 'toggle'])->middleware('permission:coupons.edit,central');
            Route::delete('/coupons/{coupon}', [BillingCouponController::class, 'destroy'])->middleware('permission:coupons.delete,central');

            // Renewals & dunning: upcoming periods, outstanding and overdue
            // invoices, and the levers for each.
            Route::get('/renewals', [BillingRenewalController::class, 'index'])->middleware('permission:renewals.view,central');
            Route::post('/renewals/{subscription}/issue', [BillingRenewalController::class, 'issue'])->middleware('permission:renewals.manage,central');
            Route::post('/renewals/invoices/{invoice}/remind', [BillingRenewalController::class, 'remind'])->middleware('permission:renewals.manage,central');
            Route::post('/renewals/invoices/{invoice}/mark-paid', [BillingRenewalController::class, 'markPaid'])->middleware('permission:renewals.manage,central');
            Route::post('/renewals/invoices/{invoice}/void', [BillingRenewalController::class, 'void'])->middleware('permission:renewals.manage,central');

            // The operator's own GST identity + invoice number series. On the
            // platform-settings vocabulary: it is configuration, not a billing
            // action against a tenant.
            Route::get('/tax', [BillingTaxController::class, 'show'])->middleware('permission:settings.view,central');
            Route::match(['put', 'patch'], '/tax', [BillingTaxController::class, 'update'])->middleware('permission:settings.edit,central');

            // The platform's own merchant accounts. `gateways.manage` holds live
            // API secrets, so it is a narrower grant than the rest of billing.
            Route::get('/gateways', [BillingGatewayController::class, 'index'])->middleware('permission:gateways.view,central');
            Route::match(['put', 'patch'], '/gateways/{gateway}', [BillingGatewayController::class, 'upsert'])->middleware('permission:gateways.manage,central');
            Route::post('/gateways/{gateway}/toggle', [BillingGatewayController::class, 'toggle'])->middleware('permission:gateways.manage,central');
            Route::post('/gateways/{gateway}/default', [BillingGatewayController::class, 'setDefault'])->middleware('permission:gateways.manage,central');
            Route::post('/gateways/{gateway}/verify', [BillingGatewayController::class, 'verify'])->middleware('permission:gateways.manage,central');
            Route::delete('/gateways/{gateway}', [BillingGatewayController::class, 'destroy'])->middleware('permission:gateways.manage,central');
        });

        // Marketplace: what the platform offers and to which plans. Two
        // catalogues (integrations, devices) on one controller — the operator
        // levers are identical — but separately permissioned, because deciding
        // which payment gateways we resell is not the same job as deciding which
        // barcode scanners we support.
        Route::prefix('marketplace')->group(function () {
            Route::get('/integrations', [MarketplaceController::class, 'integrations'])->middleware('permission:integrations.view,central');
            Route::get('/integrations/{key}/tenants', [MarketplaceController::class, 'integrationTenants'])->middleware('permission:integrations.view,central');
            Route::match(['put', 'patch'], '/integrations/{key}', [MarketplaceController::class, 'updateIntegration'])->middleware('permission:integrations.edit,central');

            Route::get('/devices', [MarketplaceController::class, 'devices'])->middleware('permission:devices.view,central');
            // {key} is 'barcode' or 'barcode:zebra' — a colon is an ordinary
            // path character, so no encoding dance is needed.
            Route::match(['put', 'patch'], '/devices/{key}', [MarketplaceController::class, 'updateDevice'])->middleware('permission:devices.edit,central');

            // Module marketplace (Volume 11 §Module Marketplace): the Industry /
            // Feature / AI packs we sell, and who holds them. Unlike the two
            // catalogues above these are database rows, hence the full CRUD.
            Route::get('/packs', [MarketplacePackController::class, 'index'])->middleware('permission:packs.view,central');
            Route::post('/packs', [MarketplacePackController::class, 'store'])->middleware('permission:packs.create,central');
            Route::match(['put', 'patch'], '/packs/{pack}', [MarketplacePackController::class, 'update'])->middleware('permission:packs.edit,central');
            Route::post('/packs/{pack}/toggle', [MarketplacePackController::class, 'toggle'])->middleware('permission:packs.edit,central');
            Route::delete('/packs/{pack}', [MarketplacePackController::class, 'destroy'])->middleware('permission:packs.delete,central');

            // Grants. Composing a pack is product work (`packs.edit`); handing one
            // to a customer is commercial and has an invoice attached, so it sits
            // on its own `packs.assign`.
            Route::get('/assignments', [MarketplaceAssignmentController::class, 'index'])->middleware('permission:packs.view,central');
            Route::get('/tenants/{tenant}/packs', [MarketplaceAssignmentController::class, 'forTenant'])->middleware('permission:packs.view,central');
            Route::post('/tenants/{tenant}/packs', [MarketplaceAssignmentController::class, 'store'])->middleware('permission:packs.assign,central');
            Route::post('/tenants/{tenant}/packs/resync', [MarketplaceAssignmentController::class, 'resync'])->middleware('permission:packs.assign,central');
            Route::delete('/assignments/{assignment}', [MarketplaceAssignmentController::class, 'destroy'])->middleware('permission:packs.assign,central');
        });

        // Tenant usage: API calls, storage, products, customers and AI usage.
        Route::prefix('usage')->group(function () {
            Route::get('/', [TenantUsageController::class, 'index'])->middleware('permission:usage.view,central');
            Route::get('/summary', [TenantUsageController::class, 'summary'])->middleware('permission:usage.view,central');
            // Declared after /summary so the fixed path is never captured as a
            // {tenant} binding.
            Route::get('/{tenant}', [TenantUsageController::class, 'show'])->middleware('permission:usage.view,central');
            Route::post('/{tenant}/refresh-storage', [TenantUsageController::class, 'refreshStorage'])->middleware('permission:usage.view,central');
        });

        // Platform Analytics (Volume 11 §"Analytics"): the four cuts the SaaS
        // owner runs the business on. Split into four endpoints rather than one
        // fat payload — server usage walks the storage disk and information_
        // schema, and nobody opening the Revenue tab should pay for that.
        Route::prefix('analytics')->group(function () {
            Route::get('/revenue', [AnalyticsController::class, 'revenue'])->middleware('permission:analytics.view,central');
            Route::get('/industry', [AnalyticsController::class, 'industry'])->middleware('permission:analytics.view,central');
            Route::get('/plans', [AnalyticsController::class, 'plans'])->middleware('permission:analytics.view,central');
            Route::get('/server-usage', [AnalyticsController::class, 'serverUsage'])->middleware('permission:analytics.view,central');
        });

        // AI Insights (Volume 11 §"AI Insights"): churn prediction, revenue
        // forecast and upsell. Deterministic statistics over platform data —
        // see App\Services\Platform\PlatformInsightsService.
        Route::prefix('insights')->group(function () {
            Route::get('/churn', [InsightsController::class, 'churn'])->middleware('permission:insights.view,central');
            Route::get('/forecast', [InsightsController::class, 'forecast'])->middleware('permission:insights.view,central');
            Route::get('/upsell', [InsightsController::class, 'upsell'])->middleware('permission:insights.view,central');
        });

        // Role catalogue + permission matrix (tuning is super-admin-only).
        Route::get('/roles', [CentralRoleController::class, 'index'])->middleware('permission:roles.view,central');
        Route::get('/permissions', [CentralRoleController::class, 'permissions'])->middleware('permission:roles.view,central');
        Route::match(['put', 'patch'], '/roles/{role}', [CentralRoleController::class, 'update'])->middleware('permission:roles.edit,central');

        // Ops (DevOps / Developer): system health, queues, backups, logs & flags.
        Route::prefix('ops')->group(function () {
            Route::get('/health', [SystemHealthController::class, 'health'])->middleware('permission:ops-health.view,central');
            Route::get('/system', [SystemHealthController::class, 'system'])->middleware('permission:ops-system.view,central');

            Route::get('/queues', [OpsQueueController::class, 'index'])->middleware('permission:ops-queues.view,central');
            Route::get('/queues/failed/{uuid}', [OpsQueueController::class, 'failedJob'])->middleware('permission:ops-queues.view,central');
            Route::post('/queues/failed/retry-all', [OpsQueueController::class, 'retryAll'])->middleware('permission:ops-queues.manage,central');
            Route::post('/queues/failed/{uuid}/retry', [OpsQueueController::class, 'retry'])->middleware('permission:ops-queues.manage,central');
            Route::delete('/queues/failed', [OpsQueueController::class, 'flush'])->middleware('permission:ops-queues.manage,central');
            Route::delete('/queues/failed/{uuid}', [OpsQueueController::class, 'forget'])->middleware('permission:ops-queues.manage,central');

            Route::get('/backups', [OpsBackupController::class, 'index'])->middleware('permission:ops-backups.view,central');
            Route::post('/backups', [OpsBackupController::class, 'run'])->middleware('permission:ops-backups.create,central');
            Route::get('/backups/{filename}/download', [OpsBackupController::class, 'download'])->middleware('permission:ops-backups.view,central');
            // Deleting an archive is the destructive tier → ops-backups.restore.
            Route::delete('/backups/{filename}', [OpsBackupController::class, 'destroy'])->middleware('permission:ops-backups.restore,central');

            Route::get('/logs', [OpsLogController::class, 'index'])->middleware('permission:ops-logs.view,central');
            Route::get('/logs/{filename}', [OpsLogController::class, 'show'])->middleware('permission:ops-logs.view,central');
            Route::get('/logs/{filename}/download', [OpsLogController::class, 'download'])->middleware('permission:ops-logs.view,central');

            // Tenant tools — operator-only surfaces that used to sit on the
            // tenant's System Tools screen (the tenant keeps Import Data only).
            Route::get('/tenant-performance/{tenant}', [TenantToolController::class, 'performance'])->middleware('permission:ops-tools.view,central');
            Route::get('/demo-data/options', [TenantToolController::class, 'demoOptions'])->middleware('permission:ops-tools.view,central');
            Route::post('/demo-data/{tenant}', [TenantToolController::class, 'generateDemo'])->middleware('permission:ops-tools.manage,central');

            Route::get('/feature-flags', [FeatureFlagController::class, 'index'])->middleware('permission:feature-flags.view,central');
            Route::post('/feature-flags', [FeatureFlagController::class, 'store'])->middleware('permission:feature-flags.edit,central');
            Route::match(['put', 'patch'], '/feature-flags/{featureFlag}', [FeatureFlagController::class, 'update'])->middleware('permission:feature-flags.edit,central');
            Route::delete('/feature-flags/{featureFlag}', [FeatureFlagController::class, 'destroy'])->middleware('permission:feature-flags.edit,central');
        });

        // Support & Customer Success: tenant health scorecard + support tickets.
        Route::prefix('support')->group(function () {
            Route::get('/health', [SupportTenantHealthController::class, 'index'])->middleware('permission:support.view,central');
            Route::get('/health/{tenant}', [SupportTenantHealthController::class, 'show'])->middleware('permission:support.view,central');

            Route::get('/agents', [SupportTicketController::class, 'agents'])->middleware('permission:tickets.view,central');
            Route::get('/tickets', [SupportTicketController::class, 'index'])->middleware('permission:tickets.view,central');
            Route::post('/tickets', [SupportTicketController::class, 'store'])->middleware('permission:tickets.create,central');
            Route::get('/tickets/{ticket}', [SupportTicketController::class, 'show'])->middleware('permission:tickets.view,central');
            Route::match(['put', 'patch'], '/tickets/{ticket}', [SupportTicketController::class, 'update'])->middleware('permission:tickets.edit,central');
            Route::post('/tickets/{ticket}/replies', [SupportTicketController::class, 'reply'])->middleware('permission:tickets.edit,central');
            Route::post('/tickets/{ticket}/close', [SupportTicketController::class, 'close'])->middleware('permission:tickets.close,central');
            // CSAT + evidence files. Downloading rides on tickets.view (same
            // reach as the thread itself); adding/removing needs tickets.edit.
            Route::post('/tickets/{ticket}/rating', [SupportTicketController::class, 'rate'])->middleware('permission:tickets.edit,central');
            Route::post('/tickets/{ticket}/attachments', [SupportTicketController::class, 'attach'])->middleware('permission:tickets.edit,central');
            Route::get('/tickets/{ticket}/attachments/{attachment}', [SupportTicketController::class, 'downloadAttachment'])->middleware('permission:tickets.view,central');
            Route::delete('/tickets/{ticket}/attachments/{attachment}', [SupportTicketController::class, 'destroyAttachment'])->middleware('permission:tickets.edit,central');
        });
    });
});
