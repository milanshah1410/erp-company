<?php

declare(strict_types=1);

use App\Modules\Industry\ComputerIt\Http\Controllers\AssetWarrantyController;
use App\Modules\Industry\ComputerIt\Http\Controllers\CustomerAssetController;
use App\Modules\Industry\ComputerIt\Http\Controllers\LicenceKeyController;
use App\Modules\Industry\ComputerIt\Http\Controllers\ServiceCallController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Computer & IT — tenant routes
|--------------------------------------------------------------------------
|
| Required from inside routes/tenant.php's middleware group, so everything here
| already carries tenancy, authentication and the tenant's permission checks.
| Only the per-route permission and module gates are stated.
|
| `module:computer_it` on every route: an install base is meaningless to a shop
| that does not support what it sells, and the menu rows reaching these screens
| are gated the same way. A route with no menu row would otherwise stay open to
| the API even with the module switched off (see EnabledModules::permissionGroupMap).
|
| Job cards are deliberately absent. A job card is a service job with a machine
| on it, so it is worked through the existing /service-jobs endpoints — this
| plugin only adds the columns and the conversion that opens one.
|
*/

Route::middleware('module:computer_it')->group(function () {
    // The install base.
    Route::get('computer-it/assets', [CustomerAssetController::class, 'index'])
        ->middleware('permission:it-assets.view');
    Route::post('computer-it/assets', [CustomerAssetController::class, 'store'])
        ->middleware('permission:it-assets.create');
    Route::get('computer-it/assets/{asset}', [CustomerAssetController::class, 'show'])
        ->middleware('permission:it-assets.view');
    /**
     * The machine's whole story — calls and job cards in one timeline. On the
     * register's own view permission rather than on service-jobs.view: reading
     * back what was done last time is the desk's job, and whoever may look a
     * machine up must be able to say it.
     */
    Route::get('computer-it/assets/{asset}/history', [CustomerAssetController::class, 'history'])
        ->middleware('permission:it-assets.view');
    Route::match(['put', 'patch'], 'computer-it/assets/{asset}', [CustomerAssetController::class, 'update'])
        ->middleware('permission:it-assets.edit');
    Route::delete('computer-it/assets/{asset}', [CustomerAssetController::class, 'destroy'])
        ->middleware('permission:it-assets.delete');

    // The warranty register.
    Route::get('computer-it/warranties', [AssetWarrantyController::class, 'index'])
        ->middleware('permission:it-warranties.view');
    /**
     * The counter question — "is this still covered, and will you come out?" —
     * asked before any of the work that would follow from the answer, so it sits
     * on view.
     */
    Route::get('computer-it/warranties/check', [AssetWarrantyController::class, 'check'])
        ->middleware('permission:it-warranties.view');
    Route::post('computer-it/warranties', [AssetWarrantyController::class, 'store'])
        ->middleware('permission:it-warranties.create');
    Route::get('computer-it/warranties/{warranty}', [AssetWarrantyController::class, 'show'])
        ->middleware('permission:it-warranties.view');
    Route::match(['put', 'patch'], 'computer-it/warranties/{warranty}', [AssetWarrantyController::class, 'update'])
        ->middleware('permission:it-warranties.edit');
    /**
     * Voiding withdraws cover a customer is relying on, so it sits on delete
     * authority rather than edit: it is the destructive act in this register,
     * even though the row survives it.
     */
    Route::post('computer-it/warranties/{warranty}/void', [AssetWarrantyController::class, 'void'])
        ->middleware('permission:it-warranties.delete');
    Route::delete('computer-it/warranties/{warranty}', [AssetWarrantyController::class, 'destroy'])
        ->middleware('permission:it-warranties.delete');

    /**
     * The licence register. Its own permission rather than riding on products:
     * a key is worth money the moment it is read, and the counter hand who
     * sells accessories has no reason to be able to read one.
     */
    Route::get('computer-it/licence-keys', [LicenceKeyController::class, 'index'])
        ->middleware('permission:it-licences.view');
    Route::post('computer-it/licence-keys', [LicenceKeyController::class, 'store'])
        ->middleware('permission:it-licences.create');
    // The only endpoint that returns a key in full — see LicenceKeyController.
    Route::get('computer-it/licence-keys/{licence}', [LicenceKeyController::class, 'show'])
        ->middleware('permission:it-licences.view');
    Route::match(['put', 'patch'], 'computer-it/licence-keys/{licence}', [LicenceKeyController::class, 'update'])
        ->middleware('permission:it-licences.edit');
    /**
     * Issuing a seat is giving away the thing itself. It sits on edit rather
     * than on view for that reason, and revoking sits on delete because it kills
     * a key the customer is using.
     */
    Route::post('computer-it/licence-keys/{licence}/allocate', [LicenceKeyController::class, 'allocate'])
        ->middleware('permission:it-licences.edit');
    Route::post('computer-it/licence-keys/{licence}/release', [LicenceKeyController::class, 'release'])
        ->middleware('permission:it-licences.edit');
    Route::post('computer-it/licence-keys/{licence}/revoke', [LicenceKeyController::class, 'revoke'])
        ->middleware('permission:it-licences.delete');
    Route::delete('computer-it/licence-keys/{licence}', [LicenceKeyController::class, 'destroy'])
        ->middleware('permission:it-licences.delete');

    // The call book.
    Route::get('computer-it/service-calls', [ServiceCallController::class, 'index'])
        ->middleware('permission:it-service-calls.view');
    Route::post('computer-it/service-calls', [ServiceCallController::class, 'store'])
        ->middleware('permission:it-service-calls.create');
    Route::get('computer-it/service-calls/{call}', [ServiceCallController::class, 'show'])
        ->middleware('permission:it-service-calls.view');
    Route::match(['put', 'patch'], 'computer-it/service-calls/{call}', [ServiceCallController::class, 'update'])
        ->middleware('permission:it-service-calls.edit');
    Route::post('computer-it/service-calls/{call}/progress', [ServiceCallController::class, 'progress'])
        ->middleware('permission:it-service-calls.edit');
    /**
     * Converting opens a job card, which is the shop taking custody of someone
     * else's machine — so it needs the authority to open one, not merely the
     * authority to work the call book.
     */
    Route::post('computer-it/service-calls/{call}/convert', [ServiceCallController::class, 'convert'])
        ->middleware('permission:service-jobs.create');
    Route::delete('computer-it/service-calls/{call}', [ServiceCallController::class, 'destroy'])
        ->middleware('permission:it-service-calls.delete');
});
