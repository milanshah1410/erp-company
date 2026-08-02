<?php

declare(strict_types=1);

use App\Modules\Industry\Optical\Http\Controllers\OpticalAppointmentController;
use App\Modules\Industry\Optical\Http\Controllers\PrescriptionAttachmentController;
use App\Modules\Industry\Optical\Http\Controllers\SpectacleDeliveryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Optical & Eyewear — tenant routes
|--------------------------------------------------------------------------
|
| Required from inside routes/tenant.php's middleware group, so everything here
| already carries tenancy, authentication and the tenant's permission checks.
| Only the per-route permission and module gates are stated.
|
| `module:optical` on every route, the read-only ones included: a menu row hidden
| from the nav is not a closed door, and these endpoints would otherwise answer a
| hand-typed URL in a workspace that never bought the vertical.
|
| The eye test and the spectacle order themselves are NOT here. They are core
| (`routes/tenant.php`, the Optical block) and this plugin does not restate them
| — it adds the diary in front of the test, the scan beside it, and the desk the
| finished pair leaves through.
|
| The attachment routes sit on the *prescription's* permissions rather than on
| any of this plugin's: whoever may read an eye test may read the paper it was
| copied from, and a separate grant would only mean a practice discovering it had
| given one without the other.
|
| Delivery has no permission of its own either. Handing a pair over is the last
| step of the workshop flow — it closes the order and raises the invoice exactly
| as core's transition does — so it sits on `optical-orders.edit`, the same
| authority. Two permissions for one act would let a shop grant the transition
| and refuse the desk, which is a state nobody wants to be in at the counter.
|
*/

Route::middleware('module:optical')->group(function () {
    // --- The appointment book ----------------------------------------------
    Route::get('optical/appointments', [OpticalAppointmentController::class, 'index'])
        ->middleware('permission:optical-appointments.view');
    Route::post('optical/appointments', [OpticalAppointmentController::class, 'store'])
        ->middleware('permission:optical-appointments.create');
    /**
     * Before the {appointment} routes below, or "status" is read as an id and a
     * shop's first attempt to mark somebody in returns a 404.
     */
    Route::post('optical/appointments/{appointment}/status', [OpticalAppointmentController::class, 'setStatus'])
        ->middleware('permission:optical-appointments.edit');
    Route::get('optical/appointments/{appointment}', [OpticalAppointmentController::class, 'show'])
        ->middleware('permission:optical-appointments.view');
    Route::match(['put', 'patch'], 'optical/appointments/{appointment}', [OpticalAppointmentController::class, 'update'])
        ->middleware('permission:optical-appointments.edit');
    Route::delete('optical/appointments/{appointment}', [OpticalAppointmentController::class, 'destroy'])
        ->middleware('permission:optical-appointments.delete');

    // --- The paper the customer brought in ----------------------------------
    Route::post('optical/prescriptions/{eyePrescription}/attachment', [PrescriptionAttachmentController::class, 'store'])
        ->middleware('permission:eye-prescriptions.edit');
    Route::get('optical/prescriptions/{eyePrescription}/attachment', [PrescriptionAttachmentController::class, 'show'])
        ->middleware('permission:eye-prescriptions.view');
    Route::delete('optical/prescriptions/{eyePrescription}/attachment', [PrescriptionAttachmentController::class, 'destroy'])
        ->middleware('permission:eye-prescriptions.delete');

    // --- The collection desk -------------------------------------------------
    Route::get('optical/delivery/board', [SpectacleDeliveryController::class, 'board'])
        ->middleware('permission:optical-orders.view');
    Route::get('optical/delivery/{opticalOrder}/warranty-quote', [SpectacleDeliveryController::class, 'warrantyQuote'])
        ->middleware('permission:optical-orders.view');
    Route::post('optical/delivery/{opticalOrder}/notify', [SpectacleDeliveryController::class, 'notify'])
        ->middleware('permission:optical-orders.edit');
    Route::post('optical/delivery/{opticalOrder}/hand-over', [SpectacleDeliveryController::class, 'handOver'])
        ->middleware('permission:optical-orders.edit');
});
