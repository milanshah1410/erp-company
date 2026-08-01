<?php

declare(strict_types=1);

use App\Modules\Industry\Healthcare\Http\Controllers\HospitalConsumptionController;
use App\Modules\Industry\Healthcare\Http\Controllers\HospitalIndentController;
use App\Modules\Industry\Healthcare\Http\Controllers\LabOrderController;
use App\Modules\Industry\Healthcare\Http\Controllers\LabTestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Healthcare — tenant routes
|--------------------------------------------------------------------------
|
| Required from inside routes/tenant.php's middleware group, so everything here
| already carries tenancy, authentication and the tenant's permission checks.
| Only the per-route permission and module gates are stated.
|
| Both groups carry an explicit `module:` gate. Menu rows are gated the same
| way, but a route whose permission group has no menu row of its own resolves to
| CORE in EnabledModules::permissionGroupMap() and would stay open to the API
| with the module switched off — so the gate is stated here rather than inferred.
|
*/

Route::middleware('module:diagnostics')->group(function () {
    /*
     * The test master. Priced, but a price list is the least of it: the sample
     * type, the method and the reference range are what make a printed result
     * mean something.
     */
    Route::get('lab-tests', [LabTestController::class, 'index'])
        ->middleware('permission:lab-tests.view');
    Route::post('lab-tests', [LabTestController::class, 'store'])
        ->middleware('permission:lab-tests.create');
    Route::get('lab-tests/{labTest}', [LabTestController::class, 'show'])
        ->middleware('permission:lab-tests.view');
    Route::match(['put', 'patch'], 'lab-tests/{labTest}', [LabTestController::class, 'update'])
        ->middleware('permission:lab-tests.edit');
    Route::delete('lab-tests/{labTest}', [LabTestController::class, 'destroy'])
        ->middleware('permission:lab-tests.delete');

    // Orders: registration, the worklist, and the report.
    Route::get('lab-orders', [LabOrderController::class, 'index'])
        ->middleware('permission:lab-orders.view');
    Route::post('lab-orders', [LabOrderController::class, 'store'])
        ->middleware('permission:lab-orders.create');
    Route::get('lab-orders/{labOrder}', [LabOrderController::class, 'show'])
        ->middleware('permission:lab-orders.view');
    Route::match(['put', 'patch'], 'lab-orders/{labOrder}', [LabOrderController::class, 'update'])
        ->middleware('permission:lab-orders.edit');
    Route::delete('lab-orders/{labOrder}', [LabOrderController::class, 'destroy'])
        ->middleware('permission:lab-orders.delete');

    // Sample collection and result entry are bench work — edit-level.
    Route::post('lab-orders/{labOrder}/collect', [LabOrderController::class, 'collect'])
        ->middleware('permission:lab-orders.edit');
    Route::post('lab-orders/{labOrder}/results', [LabOrderController::class, 'results'])
        ->middleware('permission:lab-orders.edit');

    /**
     * Verifying is where a reading becomes a result, so it sits on the approve
     * permission: the person who types a value and the person who signs it off
     * are different people in any lab that is run properly.
     */
    Route::post('lab-orders/{labOrder}/verify', [LabOrderController::class, 'verify'])
        ->middleware('permission:lab-orders.approve');
    Route::get('lab-orders/{labOrder}/report', [LabOrderController::class, 'report'])
        ->middleware('permission:lab-orders.view');
    Route::post('lab-orders/{labOrder}/deliver', [LabOrderController::class, 'deliver'])
        ->middleware('permission:lab-orders.edit');

    // Raising an invoice is not the bench's call — same reasoning as the
    // service module puts billing above edit.
    Route::post('lab-orders/{labOrder}/bill', [LabOrderController::class, 'bill'])
        ->middleware('permission:lab-orders.approve');
    Route::post('lab-orders/{labOrder}/cancel', [LabOrderController::class, 'cancel'])
        ->middleware('permission:lab-orders.delete');
});

Route::middleware('module:hospital')->group(function () {
    Route::get('hospital-indents', [HospitalIndentController::class, 'index'])
        ->middleware('permission:hospital-indents.view');
    Route::post('hospital-indents', [HospitalIndentController::class, 'store'])
        ->middleware('permission:hospital-indents.create');
    Route::get('hospital-indents/{hospitalIndent}', [HospitalIndentController::class, 'show'])
        ->middleware('permission:hospital-indents.view');
    Route::match(['put', 'patch'], 'hospital-indents/{hospitalIndent}', [HospitalIndentController::class, 'update'])
        ->middleware('permission:hospital-indents.edit');
    Route::delete('hospital-indents/{hospitalIndent}', [HospitalIndentController::class, 'destroy'])
        ->middleware('permission:hospital-indents.delete');

    // A ward submits its own indent; only the store approves or rejects it.
    Route::post('hospital-indents/{hospitalIndent}/submit', [HospitalIndentController::class, 'submit'])
        ->middleware('permission:hospital-indents.edit');
    Route::post('hospital-indents/{hospitalIndent}/approve', [HospitalIndentController::class, 'approve'])
        ->middleware('permission:hospital-indents.approve');
    Route::post('hospital-indents/{hospitalIndent}/reject', [HospitalIndentController::class, 'reject'])
        ->middleware('permission:hospital-indents.approve');
    /**
     * Issuing moves stock, which is the irreversible half of this document —
     * hence approve rather than edit. Raising and issuing an indent to your own
     * ward should not be one person's signature.
     */
    Route::post('hospital-indents/{hospitalIndent}/issue', [HospitalIndentController::class, 'issue'])
        ->middleware('permission:hospital-indents.approve');

    /**
     * Department-wise consumption. On the indent's own view permission rather
     * than `reports.view`: it is the store keeper's daily number, and they do
     * not otherwise hold the reports permission.
     */
    Route::get('hospital-consumption', [HospitalConsumptionController::class, 'index'])
        ->middleware('permission:hospital-indents.view');
    Route::get('hospital-consumption/detail', [HospitalConsumptionController::class, 'detail'])
        ->middleware('permission:hospital-indents.view');
});
