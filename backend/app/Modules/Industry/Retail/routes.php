<?php

declare(strict_types=1);

use App\Modules\Industry\Retail\Http\Controllers\GarmentColourController;
use App\Modules\Industry\Retail\Http\Controllers\GarmentFabricController;
use App\Modules\Industry\Retail\Http\Controllers\GarmentMatrixController;
use App\Modules\Industry\Retail\Http\Controllers\GarmentSizeSetController;
use App\Modules\Industry\Retail\Http\Controllers\GarmentStyleController;
use App\Modules\Industry\Retail\Http\Controllers\GarmentTrialController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Retail — tenant routes
|--------------------------------------------------------------------------
|
| Required from inside routes/tenant.php's middleware group, so everything here
| already carries tenancy, authentication and the tenant's permission checks.
| Only the per-route permission and module gates are stated.
|
| `module:garment` on every route. The menu rows that reach these screens are
| gated the same way, and a route with no menu row would otherwise stay open to
| the API even with the module switched off (see EnabledModules::permissionGroupMap).
|
| Two things a reader might look for here and not find:
|
|  - **Gift vouchers.** They are a Loyalty & Offers feature living in core
|    (routes/tenant.php, gated `module:loyalty`), because a gift shop and a
|    supermarket want them too and because PosController redeems them — core
|    cannot depend on a vertical that may not be installed.
|  - **Alterations.** A retail alteration is a `TailorAlteration` with a customer
|    on it, worked through the existing /tailor-alterations endpoints. The
|    Garment menu simply puts a second door on that screen.
|
| Permission grouping is deliberately coarser than the route list. Size runs and
| the colour palette sit on `garment-styles`, because they exist only to build
| styles and grids: a shop that lets someone define a style but not the sizes it
| comes in has drawn a line nobody in the trade recognises.
*/

Route::middleware('module:garment')->group(function () {
    // --- The style master ---------------------------------------------------
    Route::get('garment/styles', [GarmentStyleController::class, 'index'])
        ->middleware('permission:garment-styles.view');
    Route::post('garment/styles', [GarmentStyleController::class, 'store'])
        ->middleware('permission:garment-styles.create');
    Route::get('garment/styles/{style}', [GarmentStyleController::class, 'show'])
        ->middleware('permission:garment-styles.view');
    Route::match(['put', 'patch'], 'garment/styles/{style}', [GarmentStyleController::class, 'update'])
        ->middleware('permission:garment-styles.edit');
    Route::delete('garment/styles/{style}', [GarmentStyleController::class, 'destroy'])
        ->middleware('permission:garment-styles.delete');
    /**
     * Attaching products to a style edits the *products*, not the style, so it
     * sits on products.edit — the same authority the Products screen requires to
     * change what a product is.
     */
    Route::post('garment/styles/{style}/products', [GarmentStyleController::class, 'attach'])
        ->middleware('permission:products.edit');
    Route::post('garment/styles/{style}/create-product', [GarmentStyleController::class, 'createProduct'])
        ->middleware('permission:products.create');

    // --- The fabric register -------------------------------------------------
    Route::get('garment/fabrics', [GarmentFabricController::class, 'index'])
        ->middleware('permission:garment-fabrics.view');
    Route::post('garment/fabrics', [GarmentFabricController::class, 'store'])
        ->middleware('permission:garment-fabrics.create');
    Route::get('garment/fabrics/{fabric}', [GarmentFabricController::class, 'show'])
        ->middleware('permission:garment-fabrics.view');
    Route::match(['put', 'patch'], 'garment/fabrics/{fabric}', [GarmentFabricController::class, 'update'])
        ->middleware('permission:garment-fabrics.edit');
    Route::delete('garment/fabrics/{fabric}', [GarmentFabricController::class, 'destroy'])
        ->middleware('permission:garment-fabrics.delete');

    // --- The two axes of the matrix -------------------------------------------
    Route::get('garment/size-sets', [GarmentSizeSetController::class, 'index'])
        ->middleware('permission:garment-styles.view');
    Route::post('garment/size-sets', [GarmentSizeSetController::class, 'store'])
        ->middleware('permission:garment-styles.create');
    Route::get('garment/size-sets/{sizeSet}', [GarmentSizeSetController::class, 'show'])
        ->middleware('permission:garment-styles.view');
    Route::match(['put', 'patch'], 'garment/size-sets/{sizeSet}', [GarmentSizeSetController::class, 'update'])
        ->middleware('permission:garment-styles.edit');
    Route::delete('garment/size-sets/{sizeSet}', [GarmentSizeSetController::class, 'destroy'])
        ->middleware('permission:garment-styles.delete');

    Route::get('garment/colours', [GarmentColourController::class, 'index'])
        ->middleware('permission:garment-styles.view');
    Route::post('garment/colours', [GarmentColourController::class, 'store'])
        ->middleware('permission:garment-styles.create');
    Route::get('garment/colours/{colour}', [GarmentColourController::class, 'show'])
        ->middleware('permission:garment-styles.view');
    Route::match(['put', 'patch'], 'garment/colours/{colour}', [GarmentColourController::class, 'update'])
        ->middleware('permission:garment-styles.edit');
    Route::delete('garment/colours/{colour}', [GarmentColourController::class, 'destroy'])
        ->middleware('permission:garment-styles.delete');

    /**
     * The grid. On the product's own permissions rather than a garment one:
     * generating a grid creates product variants, and anyone allowed to do that
     * from the Products screen should be allowed to do it faster from here.
     */
    Route::get('garment/products/{product}/matrix', [GarmentMatrixController::class, 'show'])
        ->middleware('permission:products.view');
    Route::post('garment/products/{product}/matrix', [GarmentMatrixController::class, 'generate'])
        ->middleware('permission:products.edit');

    // --- The trial room --------------------------------------------------------
    Route::get('garment/trials', [GarmentTrialController::class, 'index'])
        ->middleware('permission:garment-trials.view');
    Route::post('garment/trials', [GarmentTrialController::class, 'store'])
        ->middleware('permission:garment-trials.create');
    Route::get('garment/trials/{trial}', [GarmentTrialController::class, 'show'])
        ->middleware('permission:garment-trials.view');
    Route::post('garment/trials/{trial}/settle', [GarmentTrialController::class, 'settle'])
        ->middleware('permission:garment-trials.edit');
    /**
     * Linking the bill is bookkeeping on the trial, not authority over the sale
     * — the sale itself was already rung up under sales permissions — so it sits
     * on edit rather than on sales.create.
     */
    Route::post('garment/trials/{trial}/link-invoice', [GarmentTrialController::class, 'linkInvoice'])
        ->middleware('permission:garment-trials.edit');
    /**
     * Cancelling releases every outstanding reservation — it is the destructive
     * act in this register even though the row survives it — so it sits on
     * delete, next to the deletion it is the safe alternative to.
     */
    Route::post('garment/trials/{trial}/cancel', [GarmentTrialController::class, 'cancel'])
        ->middleware('permission:garment-trials.delete');
    Route::delete('garment/trials/{trial}', [GarmentTrialController::class, 'destroy'])
        ->middleware('permission:garment-trials.delete');
});
