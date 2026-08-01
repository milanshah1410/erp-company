<?php

declare(strict_types=1);

use App\Modules\Industry\Agriculture\Http\Controllers\AgriLicenceController;
use App\Modules\Industry\Agriculture\Http\Controllers\FarmProductionController;
use App\Modules\Industry\Agriculture\Http\Controllers\LivestockAnimalController;
use App\Modules\Industry\Agriculture\Http\Controllers\LivestockFlockController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Agriculture — tenant routes
|--------------------------------------------------------------------------
|
| Required from inside routes/tenant.php's middleware group, so everything here
| already carries tenancy, authentication and the tenant's permission checks.
| Only the per-route permission and module gates are stated.
|
| Two module gates, matching the two halves of the vertical: `agriculture` for
| the dealer's licence register and `livestock` for the farm. A fertiliser shop
| has no animals and a poultry farm sells no licences, so gating them together
| would put dead nav in front of both. The gate is repeated on the API and not
| left to the menu, because a route with no visible menu row would otherwise stay
| reachable with the module switched off (see EnabledModules::permissionGroupMap).
|
*/

Route::middleware('module:agriculture')->group(function () {
    // The licence register — what this shop may legally sell today.
    Route::get('agri-licences', [AgriLicenceController::class, 'index'])
        ->middleware('permission:agri-licences.view');
    Route::post('agri-licences', [AgriLicenceController::class, 'store'])
        ->middleware('permission:agri-licences.create');
    Route::get('agri-licences/{agriLicence}', [AgriLicenceController::class, 'show'])
        ->middleware('permission:agri-licences.view');
    Route::match(['put', 'patch'], 'agri-licences/{agriLicence}', [AgriLicenceController::class, 'update'])
        ->middleware('permission:agri-licences.edit');
    Route::delete('agri-licences/{agriLicence}', [AgriLicenceController::class, 'destroy'])
        ->middleware('permission:agri-licences.delete');
});

Route::middleware('module:livestock')->group(function () {
    // The herd, per head.
    Route::get('livestock-animals', [LivestockAnimalController::class, 'index'])
        ->middleware('permission:livestock-animals.view');
    Route::post('livestock-animals', [LivestockAnimalController::class, 'store'])
        ->middleware('permission:livestock-animals.create');
    Route::get('livestock-animals/{livestockAnimal}', [LivestockAnimalController::class, 'show'])
        ->middleware('permission:livestock-animals.view');
    Route::match(['put', 'patch'], 'livestock-animals/{livestockAnimal}', [LivestockAnimalController::class, 'update'])
        ->middleware('permission:livestock-animals.edit');
    Route::delete('livestock-animals/{livestockAnimal}', [LivestockAnimalController::class, 'destroy'])
        ->middleware('permission:livestock-animals.delete');

    // Birds, per batch.
    Route::get('livestock-flocks', [LivestockFlockController::class, 'index'])
        ->middleware('permission:livestock-flocks.view');
    Route::post('livestock-flocks', [LivestockFlockController::class, 'store'])
        ->middleware('permission:livestock-flocks.create');
    Route::get('livestock-flocks/{livestockFlock}', [LivestockFlockController::class, 'show'])
        ->middleware('permission:livestock-flocks.view');
    Route::match(['put', 'patch'], 'livestock-flocks/{livestockFlock}', [LivestockFlockController::class, 'update'])
        ->middleware('permission:livestock-flocks.edit');
    Route::delete('livestock-flocks/{livestockFlock}', [LivestockFlockController::class, 'destroy'])
        ->middleware('permission:livestock-flocks.delete');

    /**
     * The daily book. Creating an entry moves stock, so it sits on `create`
     * rather than on an inventory permission: the milker who keys the morning
     * yield is the person who must be able to do this, and routing farm output
     * through a store-keeper's authority would mean it simply never gets keyed.
     *
     * Deleting reverses those movements, which is why it is separated out — a
     * correction to a posted day is a heavier act than recording one.
     */
    Route::get('farm-production', [FarmProductionController::class, 'index'])
        ->middleware('permission:farm-production.view');
    Route::post('farm-production', [FarmProductionController::class, 'store'])
        ->middleware('permission:farm-production.create');
    Route::get('farm-production/{farmProductionLog}', [FarmProductionController::class, 'show'])
        ->middleware('permission:farm-production.view');
    Route::match(['put', 'patch'], 'farm-production/{farmProductionLog}', [FarmProductionController::class, 'update'])
        ->middleware('permission:farm-production.edit');
    Route::delete('farm-production/{farmProductionLog}', [FarmProductionController::class, 'destroy'])
        ->middleware('permission:farm-production.delete');
});
