<?php

declare(strict_types=1);

use App\Http\Controllers\Central\PaymentWebhookController;
use App\Http\Controllers\Central\PlanController;
use App\Http\Controllers\Central\PublicPaymentController;
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
