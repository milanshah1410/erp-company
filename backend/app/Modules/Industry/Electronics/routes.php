<?php

declare(strict_types=1);

use App\Modules\Industry\Electronics\Http\Controllers\DeviceUnitController;
use App\Modules\Industry\Electronics\Http\Controllers\ServiceRequestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Electronics & Appliances — tenant routes
|--------------------------------------------------------------------------
|
| Required from inside routes/tenant.php's middleware group, so everything here
| already carries tenancy, authentication and the tenant's permission checks.
| Only the per-route permission and module gates are stated.
|
| `module:electronics` on every route: a serial register is meaningless to a shop
| that does not sell serial-numbered goods, and the menu rows that reach these
| screens are gated the same way. A route with no menu row would otherwise stay
| open to the API even with the module switched off (see
| EnabledModules::permissionGroupMap).
|
| Job cards are deliberately absent. A repair is a service job with a unit on it,
| so it is worked through the existing /service-jobs endpoints — this plugin only
| adds the columns and the conversion that opens one. AMC contracts are absent
| for the same reason: an AMC on a chiller is core's ServiceContract, unchanged.
|
*/

Route::middleware('module:electronics')->group(function () {
    // The unit register — serials, IMEIs, ownership, installation and history.
    Route::get('electronics/units', [DeviceUnitController::class, 'index'])
        ->middleware('permission:electronics-units.view');
    /**
     * The counter question — "what is this machine, and is it covered?" — asked
     * before any of the work that would follow from the answer, so it sits on
     * view. Declared before the {unit} routes so 'lookup' is not swallowed as an
     * id.
     */
    Route::get('electronics/units/lookup', [DeviceUnitController::class, 'lookup'])
        ->middleware('permission:electronics-units.view');
    Route::post('electronics/units', [DeviceUnitController::class, 'store'])
        ->middleware('permission:electronics-units.create');
    Route::get('electronics/units/{unit}', [DeviceUnitController::class, 'show'])
        ->middleware('permission:electronics-units.view');
    /**
     * Service history sits on the unit's own view permission rather than on
     * service-jobs.view: reading out what was done last time is the counter's
     * job, and whoever may look a machine up must be able to say it.
     */
    Route::get('electronics/units/{unit}/history', [DeviceUnitController::class, 'history'])
        ->middleware('permission:electronics-units.view');
    Route::match(['put', 'patch'], 'electronics/units/{unit}', [DeviceUnitController::class, 'update'])
        ->middleware('permission:electronics-units.edit');
    // Marking a machine commissioned is a fitter's act, not an edit of the record.
    Route::post('electronics/units/{unit}/install', [DeviceUnitController::class, 'install'])
        ->middleware('permission:electronics-units.edit');
    Route::delete('electronics/units/{unit}', [DeviceUnitController::class, 'destroy'])
        ->middleware('permission:electronics-units.delete');

    // The service-request desk.
    Route::get('electronics/requests', [ServiceRequestController::class, 'index'])
        ->middleware('permission:electronics-requests.view');
    Route::post('electronics/requests', [ServiceRequestController::class, 'store'])
        ->middleware('permission:electronics-requests.create');
    Route::get('electronics/requests/{serviceRequest}', [ServiceRequestController::class, 'show'])
        ->middleware('permission:electronics-requests.view');
    Route::match(['put', 'patch'], 'electronics/requests/{serviceRequest}', [ServiceRequestController::class, 'update'])
        ->middleware('permission:electronics-requests.edit');
    Route::delete('electronics/requests/{serviceRequest}', [ServiceRequestController::class, 'destroy'])
        ->middleware('permission:electronics-requests.delete');
    Route::post('electronics/requests/{serviceRequest}/progress', [ServiceRequestController::class, 'progress'])
        ->middleware('permission:electronics-requests.edit');
    /**
     * Converting opens a job card, which commits the workshop to work and puts a
     * machine into the shop's custody — so it needs the authority to open one,
     * not merely the authority to edit the desk's diary.
     */
    Route::post('electronics/requests/{serviceRequest}/convert', [ServiceRequestController::class, 'convert'])
        ->middleware('permission:service-jobs.create');
    Route::post('electronics/requests/{serviceRequest}/close', [ServiceRequestController::class, 'close'])
        ->middleware('permission:electronics-requests.edit');

    /*
     * There are no warranty routes here on purpose. Cover lives in the core
     * `warranty` module (/warranties, /warranty-claims), which this plugin
     * extends with a unit link and a parts/labour scope rather than duplicating.
     * A second register would have meant an appliance dealer holding two warranty
     * screens that disagree about who pays.
     *
     * The one place this vertical reads cover is the unit lookup above, which
     * returns the cards on a machine alongside its history — because at the
     * counter those are one question, not two.
     */
});
