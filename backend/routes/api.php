<?php

declare(strict_types=1);

use App\Http\Controllers\Central\PlanController;
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
