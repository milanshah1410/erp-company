<?php

declare(strict_types=1);

use App\Modules\Industry\Automobile\Http\Controllers\CustomerVehicleController;
use App\Modules\Industry\Automobile\Http\Controllers\VehicleBookingController;
use App\Modules\Industry\Automobile\Http\Controllers\VehicleWarrantyController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Automobile — tenant routes
|--------------------------------------------------------------------------
|
| Required from inside routes/tenant.php's middleware group, so everything here
| already carries tenancy, authentication and the tenant's permission checks.
| Only the per-route permission and module gates are stated.
|
| `module:automobile` on every route: a vehicle register is meaningless to a
| shop with no vehicles in it, and the menu rows that reach these screens are
| gated the same way. A route with no menu row would otherwise stay open to the
| API even with the module switched off (see EnabledModules::permissionGroupMap).
|
| Job cards are deliberately absent. A job card is a service job with a vehicle
| on it, so it is worked through the existing /service-jobs endpoints — the
| plugin only adds the columns and the conversion that opens one.
|
*/

Route::middleware('module:automobile')->group(function () {
    // The vehicle register — VIN, plate, papers and the service schedule.
    Route::get('automobile/vehicles', [CustomerVehicleController::class, 'index'])
        ->middleware('permission:automobile-vehicles.view');
    Route::post('automobile/vehicles', [CustomerVehicleController::class, 'store'])
        ->middleware('permission:automobile-vehicles.create');
    Route::get('automobile/vehicles/{vehicle}', [CustomerVehicleController::class, 'show'])
        ->middleware('permission:automobile-vehicles.view');
    /**
     * Service history sits on the vehicle's own view permission rather than on
     * service-jobs.view: reading out what was done last time is the counter's
     * job, and an advisor who may look up a vehicle must be able to say it.
     */
    Route::get('automobile/vehicles/{vehicle}/history', [CustomerVehicleController::class, 'history'])
        ->middleware('permission:automobile-vehicles.view');
    Route::match(['put', 'patch'], 'automobile/vehicles/{vehicle}', [CustomerVehicleController::class, 'update'])
        ->middleware('permission:automobile-vehicles.edit');
    Route::delete('automobile/vehicles/{vehicle}', [CustomerVehicleController::class, 'destroy'])
        ->middleware('permission:automobile-vehicles.delete');

    // The appointment book.
    Route::get('automobile/bookings', [VehicleBookingController::class, 'index'])
        ->middleware('permission:automobile-bookings.view');
    Route::post('automobile/bookings', [VehicleBookingController::class, 'store'])
        ->middleware('permission:automobile-bookings.create');
    Route::get('automobile/bookings/{booking}', [VehicleBookingController::class, 'show'])
        ->middleware('permission:automobile-bookings.view');
    Route::match(['put', 'patch'], 'automobile/bookings/{booking}', [VehicleBookingController::class, 'update'])
        ->middleware('permission:automobile-bookings.edit');
    Route::delete('automobile/bookings/{booking}', [VehicleBookingController::class, 'destroy'])
        ->middleware('permission:automobile-bookings.delete');
    Route::post('automobile/bookings/{booking}/progress', [VehicleBookingController::class, 'progress'])
        ->middleware('permission:automobile-bookings.edit');
    /**
     * Converting opens a job card, which is the shop taking custody of someone
     * else's vehicle — so it needs the authority to open one, not merely the
     * authority to edit the appointment book.
     */
    Route::post('automobile/bookings/{booking}/convert', [VehicleBookingController::class, 'convert'])
        ->middleware('permission:service-jobs.create');
    Route::post('automobile/bookings/{booking}/close', [VehicleBookingController::class, 'close'])
        ->middleware('permission:automobile-bookings.edit');

    // The warranty register.
    Route::get('automobile/warranties', [VehicleWarrantyController::class, 'index'])
        ->middleware('permission:automobile-warranties.view');
    /**
     * The counter question — "is this still covered?" — asked before any of the
     * work that would follow from the answer, so it sits on view.
     */
    Route::get('automobile/warranties/check', [VehicleWarrantyController::class, 'check'])
        ->middleware('permission:automobile-warranties.view');
    Route::post('automobile/warranties', [VehicleWarrantyController::class, 'store'])
        ->middleware('permission:automobile-warranties.create');
    Route::get('automobile/warranties/{warranty}', [VehicleWarrantyController::class, 'show'])
        ->middleware('permission:automobile-warranties.view');
    Route::match(['put', 'patch'], 'automobile/warranties/{warranty}', [VehicleWarrantyController::class, 'update'])
        ->middleware('permission:automobile-warranties.edit');
    /**
     * Voiding withdraws cover a customer is relying on, so it sits on delete
     * authority rather than edit: it is the destructive act in this register,
     * even though the row survives it.
     */
    Route::post('automobile/warranties/{warranty}/void', [VehicleWarrantyController::class, 'void'])
        ->middleware('permission:automobile-warranties.delete');
    Route::delete('automobile/warranties/{warranty}', [VehicleWarrantyController::class, 'destroy'])
        ->middleware('permission:automobile-warranties.delete');
});
