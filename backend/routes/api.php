<?php

declare(strict_types=1);

use App\Http\Controllers\Central\AuthController as CentralAuthController;
use App\Http\Controllers\Central\OperatorController;
use App\Http\Controllers\Central\PaymentWebhookController;
use App\Http\Controllers\Central\PlanController;
use App\Http\Controllers\Central\PublicPaymentController;
use App\Http\Controllers\Central\RoleController as CentralRoleController;
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

        // Role catalogue + permission matrix (tuning is super-admin-only).
        Route::get('/roles', [CentralRoleController::class, 'index'])->middleware('permission:roles.view,central');
        Route::get('/permissions', [CentralRoleController::class, 'permissions'])->middleware('permission:roles.view,central');
        Route::match(['put', 'patch'], '/roles/{role}', [CentralRoleController::class, 'update'])->middleware('permission:roles.edit,central');
    });
});
