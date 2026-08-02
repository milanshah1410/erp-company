<?php

declare(strict_types=1);

use App\Modules\Industry\Cosmetics\Http\Controllers\OpenUnitController;
use App\Modules\Industry\Cosmetics\Http\Controllers\ShadeCardController;
use App\Modules\Industry\Cosmetics\Http\Controllers\ShadeController;
use App\Modules\Industry\Cosmetics\Http\Controllers\ShelfLifeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Beauty & Personal Care — tenant routes
|--------------------------------------------------------------------------
|
| Required from inside routes/tenant.php's middleware group, so everything here
| already carries tenancy, authentication and the tenant's permission checks.
| Only the per-route permission and module gates are stated.
|
| `module:cosmetics` on every route, including the read-only counter check: a
| menu row hidden from the nav is not a closed door, and these endpoints would
| otherwise stay open to a hand-typed URL in a workspace that never bought the
| vertical (see EnabledModules::permissionGroupMap).
|
| Two permissions, not one, and the split is not organisational — it is that
| these are two different jobs done by two different people. `cosmetic-shades`
| is the buyer's: which shades the shop carries and which of them are on a
| range's card. `cosmetic-shelf-life` is the floor's: what is going off, which
| testers are past their date, and whether the till refuses short stock. A
| counter assistant opens a tester every week and will never key a shade card; a
| buyer sets up a forty-shade range and does not touch the bin.
|
| There are no routes here for barcodes, offers or membership, and that is the
| point — barcodes are `inventory`/`devices`, and offers and membership are core
| Loyalty & Offers. See the note in CosmeticsPlugin about which module already
| owns each of the six things this trade asked for.
|
*/

Route::middleware('module:cosmetics')->group(function () {
    // --- The shade master ---------------------------------------------------
    Route::get('cosmetics/shades', [ShadeController::class, 'index'])
        ->middleware('permission:cosmetic-shades.view');
    Route::post('cosmetics/shades', [ShadeController::class, 'store'])
        ->middleware('permission:cosmetic-shades.create');
    Route::get('cosmetics/shades/{shade}', [ShadeController::class, 'show'])
        ->middleware('permission:cosmetic-shades.view');
    Route::match(['put', 'patch'], 'cosmetics/shades/{shade}', [ShadeController::class, 'update'])
        ->middleware('permission:cosmetic-shades.edit');
    Route::delete('cosmetics/shades/{shade}', [ShadeController::class, 'destroy'])
        ->middleware('permission:cosmetic-shades.delete');

    // --- The shade card -----------------------------------------------------
    Route::get('cosmetics/shade-card/{product}', [ShadeCardController::class, 'show'])
        ->middleware('permission:cosmetic-shades.view');
    /**
     * Generating a card creates product variants — new sellable SKUs with their
     * own barcodes — so it sits on the shade register's create grant *and*
     * products.create. Whoever may put forty new lines on the shelf must be
     * somebody who may create a product; the shade permission alone would be a
     * side door into the catalogue.
     */
    Route::post('cosmetics/shade-card', [ShadeCardController::class, 'generate'])
        ->middleware(['permission:cosmetic-shades.create', 'permission:products.create']);

    // --- Testers and opened stock -------------------------------------------
    Route::get('cosmetics/open-units', [OpenUnitController::class, 'index'])
        ->middleware('permission:cosmetic-shelf-life.view');
    /**
     * Opening a unit writes off stock, which is why it is `.create` on this
     * register rather than a stock permission: it is a counter act done several
     * times a week by people who have no business making stock adjustments, and
     * routing it through the adjustment screen is how it stops being recorded at
     * all.
     */
    Route::post('cosmetics/open-units', [OpenUnitController::class, 'store'])
        ->middleware('permission:cosmetic-shelf-life.create');
    Route::get('cosmetics/open-units/{unit}', [OpenUnitController::class, 'show'])
        ->middleware('permission:cosmetic-shelf-life.view');
    Route::post('cosmetics/open-units/{unit}/close', [OpenUnitController::class, 'close'])
        ->middleware('permission:cosmetic-shelf-life.edit');
    Route::delete('cosmetics/open-units/{unit}', [OpenUnitController::class, 'destroy'])
        ->middleware('permission:cosmetic-shelf-life.delete');

    // --- Shelf life ---------------------------------------------------------
    Route::get('cosmetics/shelf-life', [ShelfLifeController::class, 'board'])
        ->middleware('permission:cosmetic-shelf-life.view');
    /**
     * The counter's pre-check. Read-only and on the board's view permission:
     * whoever may be told that a foundation went off last month is whoever is
     * standing at the till, and refusing them the answer only means the sale
     * happens without it.
     */
    Route::post('cosmetics/shelf-life/check', [ShelfLifeController::class, 'check'])
        ->middleware('permission:cosmetic-shelf-life.view');
    /**
     * Whether the till refuses expired stock is a decision about the shop's
     * licence to trade, so it sits on delete authority rather than edit — the
     * same rule the toy shop's certificate withdrawal follows.
     */
    Route::put('cosmetics/shelf-life/policy', [ShelfLifeController::class, 'updatePolicy'])
        ->middleware('permission:cosmetic-shelf-life.delete');
});
