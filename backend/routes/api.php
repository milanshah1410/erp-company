<?php

declare(strict_types=1);

use App\Http\Controllers\Central\AuthController as CentralAuthController;
use App\Http\Controllers\Central\BillingInvoiceController;
use App\Http\Controllers\Central\BillingPlanController;
use App\Http\Controllers\Central\BillingRevenueController;
use App\Http\Controllers\Central\BillingSubscriptionController;
use App\Http\Controllers\Central\OperatorController;
use App\Http\Controllers\Central\Ops\BackupController as OpsBackupController;
use App\Http\Controllers\Central\Ops\FeatureFlagController;
use App\Http\Controllers\Central\Ops\LogController as OpsLogController;
use App\Http\Controllers\Central\Ops\QueueController as OpsQueueController;
use App\Http\Controllers\Central\Ops\SystemHealthController;
use App\Http\Controllers\Central\PaymentWebhookController;
use App\Http\Controllers\Central\Support\TenantHealthController as SupportTenantHealthController;
use App\Http\Controllers\Central\Support\TicketController as SupportTicketController;
use App\Http\Controllers\Central\PlanController;
use App\Http\Controllers\Central\PublicPaymentController;
use App\Http\Controllers\Central\RoleController as CentralRoleController;
use App\Http\Controllers\Central\TenantController as CentralTenantController;
use App\Http\Controllers\Central\TenantFileController;
use App\Http\Controllers\Central\RazorpayWebhookController;
use App\Http\Controllers\Central\RegistrationController;
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

// Public pricing / plan catalogue (for the signup + upgrade pages).
Route::get('/plans', [PlanController::class, 'index']);

// Self-serve tenant registration + email verification + provisioning status.
Route::get('/check-email', [RegistrationController::class, 'checkEmail']);
Route::post('/register', [RegistrationController::class, 'register']);
Route::post('/register/logo', [RegistrationController::class, 'uploadLogo']);
Route::post('/verify-email', [RegistrationController::class, 'verifyEmail']);
Route::post('/resend-verification', [RegistrationController::class, 'resendVerification']);
Route::get('/provisioning-status/{registration}', [RegistrationController::class, 'provisioningStatus']);

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

            Route::get('/invoices', [BillingInvoiceController::class, 'index'])->middleware('permission:invoices.view,central');
            Route::post('/invoices/{invoice}/refund', [BillingInvoiceController::class, 'refund'])->middleware('permission:invoices.refund,central');

            Route::get('/revenue', [BillingRevenueController::class, 'summary'])->middleware('permission:revenue.view,central');
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
        });
    });
});
