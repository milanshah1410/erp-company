<?php

declare(strict_types=1);

use App\Modules\Industry\Sports\Http\Controllers\RentalAssetController;
use App\Modules\Industry\Sports\Http\Controllers\RentalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sports & Fitness — tenant routes
|--------------------------------------------------------------------------
|
| Required from inside routes/tenant.php's middleware group, so everything here
| already carries tenancy, authentication and the tenant's permission checks.
| Only the per-route permission and module gates are stated.
|
| `module:sports` on every route, including the read-only availability lookup: a
| menu row hidden from the nav is not a closed door, and these endpoints would
| otherwise stay open to a hand-typed URL in a workspace that never bought the
| vertical (see EnabledModules::permissionGroupMap).
|
| Two permissions, split by what the person holding each of them decides:
|
|  - `rental-assets` is the fleet — what the shop owns, what it charges for it and
|    what condition it is in. Buying a bike, pricing it and writing it off are
|    ownership decisions, and they are not the counter's.
|  - `rentals` is the desk — booking, handing over, taking back. The counter's own
|    work, and it needs `.view` on the fleet to do any of it, which is why the
|    counter role carries the fleet at view and the desk at contribute.
|
| Three routes deliberately sit on permissions from elsewhere, because the act is
| not really a hire desk act:
|
|  - **Settling** raises an invoice and refunds money, so it takes `sales.create`.
|    Taking a bike back and writing a bill for it are routinely different people,
|    and the same rule the Gift module follows for assembling a hamper on
|    `stock.create`.
|  - **Cancelling** and **deleting** a booking sit on `rentals.delete` — cancelling
|    is the safe alternative to the deletion next to it, so they belong together.
|
| There are no routes here for sizes, colours, brands, barcodes, warranty cards or
| repair job cards, and that is the point — see the note in SportsPlugin about
| which module already owns each of them.
|
*/

Route::middleware('module:sports')->group(function () {
    // --- The fleet -----------------------------------------------------------
    Route::get('sports/rental-assets', [RentalAssetController::class, 'index'])
        ->middleware('permission:rental-assets.view');
    /**
     * Before the {asset} route, or "available" and "utilisation" are swallowed as
     * asset codes and the counter's own lookup 404s.
     */
    Route::get('sports/rental-assets/available', [RentalAssetController::class, 'available'])
        ->middleware('permission:rental-assets.view');
    /**
     * On the fleet's view permission rather than a reporting one: whether a bike
     * has earned its keep is the same book as what the bike is, and a shop that
     * lets someone maintain the fleet without seeing how it performs has drawn a
     * line nobody in the trade recognises.
     */
    Route::get('sports/rental-assets/utilisation', [RentalAssetController::class, 'utilisation'])
        ->middleware('permission:rental-assets.view');
    Route::post('sports/rental-assets', [RentalAssetController::class, 'store'])
        ->middleware('permission:rental-assets.create');
    Route::get('sports/rental-assets/{asset}', [RentalAssetController::class, 'show'])
        ->middleware('permission:rental-assets.view');
    Route::match(['put', 'patch'], 'sports/rental-assets/{asset}', [RentalAssetController::class, 'update'])
        ->middleware('permission:rental-assets.edit');
    Route::delete('sports/rental-assets/{asset}', [RentalAssetController::class, 'destroy'])
        ->middleware('permission:rental-assets.delete');

    // --- The hire desk -------------------------------------------------------
    Route::get('sports/rentals', [RentalController::class, 'index'])
        ->middleware('permission:rentals.view');
    Route::post('sports/rentals', [RentalController::class, 'store'])
        ->middleware('permission:rentals.create');
    Route::get('sports/rentals/{rental}', [RentalController::class, 'show'])
        ->middleware('permission:rentals.view');
    Route::match(['put', 'patch'], 'sports/rentals/{rental}', [RentalController::class, 'update'])
        ->middleware('permission:rentals.edit');

    /**
     * Handing over, extending and taking back are the desk's ordinary work — the
     * person at the counter must be able to do all three — so they sit on `.edit`
     * rather than on anything higher, even though handing over commits the shop's
     * kit to somebody for a weekend.
     */
    Route::post('sports/rentals/{rental}/issue', [RentalController::class, 'issue'])
        ->middleware('permission:rentals.edit');
    Route::post('sports/rentals/{rental}/extend', [RentalController::class, 'extend'])
        ->middleware('permission:rentals.edit');
    Route::post('sports/rentals/{rental}/return', [RentalController::class, 'returnItems'])
        ->middleware('permission:rentals.edit');

    // Writing the bill and deciding what happens to the deposit — see the note
    // above on why this is the sales register's authority and not the desk's.
    Route::post('sports/rentals/{rental}/close', [RentalController::class, 'close'])
        ->middleware('permission:sales.create');

    Route::post('sports/rentals/{rental}/cancel', [RentalController::class, 'cancel'])
        ->middleware('permission:rentals.delete');
    Route::delete('sports/rentals/{rental}', [RentalController::class, 'destroy'])
        ->middleware('permission:rentals.delete');
});
