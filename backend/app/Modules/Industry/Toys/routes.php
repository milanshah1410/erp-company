<?php

declare(strict_types=1);

use App\Modules\Industry\Toys\Http\Controllers\AgeGradeController;
use App\Modules\Industry\Toys\Http\Controllers\ComplianceController;
use App\Modules\Industry\Toys\Http\Controllers\SafetyCategoryController;
use App\Modules\Industry\Toys\Http\Controllers\SafetyCertificateController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Toys & Games — tenant routes
|--------------------------------------------------------------------------
|
| Required from inside routes/tenant.php's middleware group, so everything here
| already carries tenancy, authentication and the tenant's permission checks.
| Only the per-route permission and module gates are stated.
|
| `module:toys` on every route, including the read-only counter lookups: a menu
| row hidden from the nav is not a closed door, and these endpoints would
| otherwise stay open to a hand-typed URL in a workspace that never bought the
| vertical (see EnabledModules::permissionGroupMap).
|
| One permission across three screens. The age bands, the safety classes and the
| certificate register are one book to whoever keeps it: the person who decides
| an article has small parts is the person who decides it is not for under-3s and
| the person who files the BIS licence behind it. Three grants that are always
| given together are one grant with extra steps.
|
| There are no routes here for combo packs, gift wrapping, barcodes or offers,
| and that is the point — see the note in ToysPlugin about which module already
| owns each of them.
|
*/

Route::middleware('module:toys')->group(function () {
    // --- Age bands ---------------------------------------------------------
    Route::get('toys/age-grades', [AgeGradeController::class, 'index'])
        ->middleware('permission:toy-safety.view');
    Route::post('toys/age-grades', [AgeGradeController::class, 'store'])
        ->middleware('permission:toy-safety.create');
    Route::get('toys/age-grades/{grade}', [AgeGradeController::class, 'show'])
        ->middleware('permission:toy-safety.view');
    Route::match(['put', 'patch'], 'toys/age-grades/{grade}', [AgeGradeController::class, 'update'])
        ->middleware('permission:toy-safety.edit');
    Route::delete('toys/age-grades/{grade}', [AgeGradeController::class, 'destroy'])
        ->middleware('permission:toy-safety.delete');

    // --- Safety classes ----------------------------------------------------
    Route::get('toys/safety-categories', [SafetyCategoryController::class, 'index'])
        ->middleware('permission:toy-safety.view');
    Route::post('toys/safety-categories', [SafetyCategoryController::class, 'store'])
        ->middleware('permission:toy-safety.create');
    Route::get('toys/safety-categories/{category}', [SafetyCategoryController::class, 'show'])
        ->middleware('permission:toy-safety.view');
    Route::match(['put', 'patch'], 'toys/safety-categories/{category}', [SafetyCategoryController::class, 'update'])
        ->middleware('permission:toy-safety.edit');
    Route::delete('toys/safety-categories/{category}', [SafetyCategoryController::class, 'destroy'])
        ->middleware('permission:toy-safety.delete');

    // --- Certificates ------------------------------------------------------
    Route::get('toys/certificates', [SafetyCertificateController::class, 'index'])
        ->middleware('permission:toy-safety.view');
    Route::post('toys/certificates', [SafetyCertificateController::class, 'store'])
        ->middleware('permission:toy-safety.create');
    Route::get('toys/certificates/{certificate}', [SafetyCertificateController::class, 'show'])
        ->middleware('permission:toy-safety.view');
    Route::match(['put', 'patch'], 'toys/certificates/{certificate}', [SafetyCertificateController::class, 'update'])
        ->middleware('permission:toy-safety.edit');
    /**
     * Withdrawing the cover a shop is relying on to sell is the destructive act
     * in this register, even though the row survives it — so it sits on delete
     * authority rather than edit, exactly as voiding a warranty does.
     */
    Route::post('toys/certificates/{certificate}/revoke', [SafetyCertificateController::class, 'revoke'])
        ->middleware('permission:toy-safety.delete');
    Route::delete('toys/certificates/{certificate}', [SafetyCertificateController::class, 'destroy'])
        ->middleware('permission:toy-safety.delete');

    /**
     * The counter lookups. Both read-only and both on the register's view
     * permission: whoever may be told that a toy is unsuitable is whoever is
     * standing at the till, and refusing them the answer only means the sale
     * happens without it.
     *
     * compliance-check is a POST because it carries a cart, not because it
     * writes one — the same shape, and the same moment in a checkout, as the
     * pharmacy module's prescription check.
     */
    Route::post('toys/compliance-check', [ComplianceController::class, 'inspect'])
        ->middleware('permission:toy-safety.view');
    Route::get('toys/compliance/{product}', [ComplianceController::class, 'product'])
        ->middleware('permission:toy-safety.view');
});
