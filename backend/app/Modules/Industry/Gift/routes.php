<?php

declare(strict_types=1);

use App\Modules\Industry\Gift\Http\Controllers\GiftOccasionController;
use App\Modules\Industry\Gift\Http\Controllers\GiftPackController;
use App\Modules\Industry\Gift\Http\Controllers\GiftWrapController;
use App\Modules\Industry\Gift\Http\Controllers\GiftWrapOptionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Gift & Novelty — tenant routes
|--------------------------------------------------------------------------
|
| Required from inside routes/tenant.php's middleware group, so everything here
| already carries tenancy, authentication and the tenant's permission checks.
| Only the per-route permission and module gates are stated.
|
| `module:gift` on every route, matching the menu rows that reach these screens:
| a route with no module gate stays open to the API even with the module switched
| off, because the menu is what EnabledModules derives the gate from.
|
| Two things a reader might look for here and not find:
|
|  - **Gift vouchers.** A Loyalty & Offers feature living in core
|    (routes/tenant.php, gated `module:loyalty`), because a supermarket and a
|    garment showroom sell them too, and because PosController redeems them —
|    core cannot depend on a vertical that may not be installed.
|  - **Variants and barcode labels.** Ordinary products and the `devices` module.
|
| Permission grouping is coarser than the route list on purpose. The wrapping
| *master* sits on `gift-wraps` alongside the parcels rather than getting a
| permission of its own: a shop that lets someone take wrapping orders but not
| define what wrapping it offers has drawn a line nobody in the trade recognises,
| and the master is four rows that change twice a year.
*/

Route::middleware('module:gift')->group(function () {
    // --- The wrap desk -------------------------------------------------------
    Route::get('gift/wrap-options', [GiftWrapOptionController::class, 'index'])
        ->middleware('permission:gift-wraps.view');
    Route::post('gift/wrap-options', [GiftWrapOptionController::class, 'store'])
        ->middleware('permission:gift-wraps.create');
    Route::get('gift/wrap-options/{option}', [GiftWrapOptionController::class, 'show'])
        ->middleware('permission:gift-wraps.view');
    Route::match(['put', 'patch'], 'gift/wrap-options/{option}', [GiftWrapOptionController::class, 'update'])
        ->middleware('permission:gift-wraps.edit');
    Route::delete('gift/wrap-options/{option}', [GiftWrapOptionController::class, 'destroy'])
        ->middleware('permission:gift-wraps.delete');

    Route::get('gift/wraps', [GiftWrapController::class, 'index'])
        ->middleware('permission:gift-wraps.view');
    Route::post('gift/wraps', [GiftWrapController::class, 'store'])
        ->middleware('permission:gift-wraps.create');
    Route::get('gift/wraps/{wrap}', [GiftWrapController::class, 'show'])
        ->middleware('permission:gift-wraps.view');
    Route::match(['put', 'patch'], 'gift/wraps/{wrap}', [GiftWrapController::class, 'update'])
        ->middleware('permission:gift-wraps.edit');
    /**
     * Wrapping and handing over are the desk's ordinary work — the person on the
     * bench with the scissors must be able to do both — so they sit on `.edit`
     * rather than on anything higher, even though wrapping posts a stock movement.
     */
    Route::post('gift/wraps/{wrap}/wrap', [GiftWrapController::class, 'wrap'])
        ->middleware('permission:gift-wraps.edit');
    Route::post('gift/wraps/{wrap}/collect', [GiftWrapController::class, 'collect'])
        ->middleware('permission:gift-wraps.edit');
    /**
     * Cancelling is the destructive act in this register even though the row
     * survives it — the material stays consumed and the parcel is written off — so
     * it sits on delete, next to the deletion it is the safe alternative to.
     */
    Route::post('gift/wraps/{wrap}/cancel', [GiftWrapController::class, 'cancel'])
        ->middleware('permission:gift-wraps.delete');
    Route::delete('gift/wraps/{wrap}', [GiftWrapController::class, 'destroy'])
        ->middleware('permission:gift-wraps.delete');

    // --- Hamper packs --------------------------------------------------------
    Route::get('gift/packs', [GiftPackController::class, 'index'])
        ->middleware('permission:gift-packs.view');
    Route::post('gift/packs', [GiftPackController::class, 'store'])
        ->middleware('permission:gift-packs.create');
    Route::get('gift/packs/{pack}', [GiftPackController::class, 'show'])
        ->middleware('permission:gift-packs.view');
    Route::match(['put', 'patch'], 'gift/packs/{pack}', [GiftPackController::class, 'update'])
        ->middleware('permission:gift-packs.edit');
    Route::delete('gift/packs/{pack}', [GiftPackController::class, 'destroy'])
        ->middleware('permission:gift-packs.delete');

    Route::get('gift/packs/{pack}/builds', [GiftPackController::class, 'builds'])
        ->middleware('permission:gift-packs.view');
    /**
     * Assembling a batch moves stock in both directions, which is why it sits on
     * `stock.create` rather than on `gift-packs.edit`: designing a hamper is
     * merchandising, emptying the shelves to make forty of them is not, and the
     * two are routinely different people. Same rule the manufacturing screens
     * follow for a production run.
     */
    Route::post('gift/packs/{pack}/build', [GiftPackController::class, 'build'])
        ->middleware('permission:stock.create');
    /**
     * Unwinding one is a stock correction, so it needs the authority to adjust
     * stock — a level above posting it.
     */
    Route::post('gift/packs/{pack}/builds/{build}/unbuild', [GiftPackController::class, 'unbuild'])
        ->middleware('permission:stock.edit');

    // --- The occasion board --------------------------------------------------
    Route::get('gift/occasions', [GiftOccasionController::class, 'index'])
        ->middleware('permission:gift-occasions.view');
    Route::get('gift/occasions/upcoming', [GiftOccasionController::class, 'upcoming'])
        ->middleware('permission:gift-occasions.view');
    Route::post('gift/occasions', [GiftOccasionController::class, 'store'])
        ->middleware('permission:gift-occasions.create');
    /**
     * Bulk-lifting dates out of the customer master creates rows in quantity, so
     * it sits on create — and it is deliberately not on view, which is what a
     * "just have a look" screen would otherwise be able to do.
     */
    Route::post('gift/occasions/import', [GiftOccasionController::class, 'import'])
        ->middleware('permission:gift-occasions.create');
    Route::get('gift/occasions/{occasion}', [GiftOccasionController::class, 'show'])
        ->middleware('permission:gift-occasions.view');
    Route::match(['put', 'patch'], 'gift/occasions/{occasion}', [GiftOccasionController::class, 'update'])
        ->middleware('permission:gift-occasions.edit');
    /**
     * Sending a reminder now texts a customer, so it takes edit rather than view:
     * it is an outgoing message on the shop's behalf, and it stamps the record as
     * reminded so the nightly run will not repeat it.
     */
    Route::post('gift/occasions/{occasion}/remind', [GiftOccasionController::class, 'remind'])
        ->middleware('permission:gift-occasions.edit');
    Route::delete('gift/occasions/{occasion}', [GiftOccasionController::class, 'destroy'])
        ->middleware('permission:gift-occasions.delete');
});
