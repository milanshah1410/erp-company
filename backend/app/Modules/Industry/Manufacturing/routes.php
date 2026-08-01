<?php

declare(strict_types=1);

use App\Modules\Industry\Manufacturing\Http\Controllers\MachineController;
use App\Modules\Industry\Manufacturing\Http\Controllers\ProductionScrapController;
use App\Modules\Industry\Manufacturing\Http\Controllers\QualityInspectionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Manufacturing — tenant routes
|--------------------------------------------------------------------------
|
| Required from inside routes/tenant.php's middleware group, so everything here
| already carries tenancy, authentication and the tenant's permission checks.
| Only the per-route permission and module gates are stated.
|
| `module:manufacturing` on every route: a machine list, an inspection and a
| scrap note mean nothing to a shop that does not convert anything, and the menu
| rows that reach them are gated the same way. A route with no menu row would
| otherwise stay open to the API even with the module switched off (see
| EnabledModules::permissionGroupMap).
|
*/

Route::middleware('module:manufacturing')->group(function () {
    // --- Machines -----------------------------------------------------------
    Route::get('machines', [MachineController::class, 'index'])
        ->middleware('permission:machines.view');
    /**
     * Declared before the {machine} routes: 'utilisation' would otherwise be
     * bound as a machine id and 404 on every call.
     */
    Route::get('machines/utilisation', [MachineController::class, 'utilisation'])
        ->middleware('permission:machines.view');
    Route::post('machines', [MachineController::class, 'store'])
        ->middleware('permission:machines.create');
    Route::get('machines/{machine}', [MachineController::class, 'show'])
        ->middleware('permission:machines.view');
    Route::match(['put', 'patch'], 'machines/{machine}', [MachineController::class, 'update'])
        ->middleware('permission:machines.edit');
    Route::delete('machines/{machine}', [MachineController::class, 'destroy'])
        ->middleware('permission:machines.delete');

    /**
     * Downtime sits on the machine's `edit` permission, not `create`: logging a
     * stop is recording what the machine did, and the operator or supervisor who
     * needs to do it mid-shift is not the person who adds plant to the register.
     */
    Route::get('machines/{machine}/downtimes', [MachineController::class, 'downtimes'])
        ->middleware('permission:machines.view');
    Route::post('machines/{machine}/downtimes', [MachineController::class, 'logDowntime'])
        ->middleware('permission:machines.edit');
    Route::post('machines/{machine}/downtimes/{downtime}/end', [MachineController::class, 'endDowntime'])
        ->middleware('permission:machines.edit');

    /**
     * Charging machine hours to a run edits that run's cost, so it is gated on
     * the order rather than on the machine — the machine is only the rate card.
     */
    Route::post('manufacturing-orders/{manufacturingOrder}/machine-time', [MachineController::class, 'chargeOrderTime'])
        ->middleware('permission:manufacturing-orders.edit');

    // --- Quality inspection --------------------------------------------------
    Route::get('quality-inspections', [QualityInspectionController::class, 'index'])
        ->middleware('permission:quality-inspections.view');
    // Both before {qualityInspection}, for the same reason as above.
    Route::get('quality-inspections/defect-analysis', [QualityInspectionController::class, 'defectAnalysis'])
        ->middleware('permission:quality-inspections.view');
    Route::get('quality-inspections/order-options', [QualityInspectionController::class, 'orderOptions'])
        ->middleware('permission:quality-inspections.view');
    Route::post('quality-inspections', [QualityInspectionController::class, 'store'])
        ->middleware('permission:quality-inspections.create');
    Route::get('quality-inspections/{qualityInspection}', [QualityInspectionController::class, 'show'])
        ->middleware('permission:quality-inspections.view');
    Route::match(['put', 'patch'], 'quality-inspections/{qualityInspection}', [QualityInspectionController::class, 'update'])
        ->middleware('permission:quality-inspections.edit');
    Route::delete('quality-inspections/{qualityInspection}', [QualityInspectionController::class, 'destroy'])
        ->middleware('permission:quality-inspections.delete');
    /**
     * Raising scrap from an inspection writes off stock, so it is held on the
     * scrap module's create permission rather than QC's: an inspector says what
     * failed, someone with authority over stock says it is written off.
     */
    Route::post('quality-inspections/{qualityInspection}/raise-scrap', [QualityInspectionController::class, 'raiseScrap'])
        ->middleware('permission:production-scrap.create');

    // --- Scrap ---------------------------------------------------------------
    Route::get('production-scraps', [ProductionScrapController::class, 'index'])
        ->middleware('permission:production-scrap.view');
    Route::post('production-scraps', [ProductionScrapController::class, 'store'])
        ->middleware('permission:production-scrap.create');
    Route::get('production-scraps/{productionScrap}', [ProductionScrapController::class, 'show'])
        ->middleware('permission:production-scrap.view');
    Route::match(['put', 'patch'], 'production-scraps/{productionScrap}', [ProductionScrapController::class, 'update'])
        ->middleware('permission:production-scrap.edit');
    Route::delete('production-scraps/{productionScrap}', [ProductionScrapController::class, 'destroy'])
        ->middleware('permission:production-scrap.delete');
});
