<?php

declare(strict_types=1);

use App\Modules\Industry\Stationery\Http\Controllers\ClassKitController;
use App\Modules\Industry\Stationery\Http\Controllers\InstitutionController;
use App\Modules\Industry\Stationery\Http\Controllers\SchoolOrderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Stationery & School Supplies — tenant routes
|--------------------------------------------------------------------------
|
| Required from inside routes/tenant.php's middleware group, so everything here
| already carries tenancy, authentication and the tenant's permission checks.
| Only the per-route permission and module gates are stated.
|
| `module:stationery` on every route, including the school-order board. That
| board reads core sales orders, so without the explicit gate it would resolve to
| CORE in EnabledModules::permissionGroupMap and stay open to the API for a shop
| that never bought the vertical — the same trap any route without a menu row of
| its own falls into.
|
| Notably absent: a school-order document. A school's order *is* a sales order on
| the `institution` channel, worked through the existing /sales-orders endpoints;
| this plugin only raises it from a booklist and reads it back as a season.
|
*/

Route::middleware('module:stationery')->group(function () {
    // The institutions the shop supplies.
    Route::get('stationery/institutions', [InstitutionController::class, 'index'])
        ->middleware('permission:stationery-institutions.view');
    Route::post('stationery/institutions', [InstitutionController::class, 'store'])
        ->middleware('permission:stationery-institutions.create');
    Route::get('stationery/institutions/{institution}', [InstitutionController::class, 'show'])
        ->middleware('permission:stationery-institutions.view');
    /**
     * "Where are we with Sunrise?" — the lists and everything supplied against
     * them. On the register's own view permission: it is reading the account, and
     * whoever may open a school must be able to see how its season is going.
     */
    Route::get('stationery/institutions/{institution}/season', [InstitutionController::class, 'season'])
        ->middleware('permission:stationery-institutions.view');
    Route::match(['put', 'patch'], 'stationery/institutions/{institution}', [InstitutionController::class, 'update'])
        ->middleware('permission:stationery-institutions.edit');
    Route::delete('stationery/institutions/{institution}', [InstitutionController::class, 'destroy'])
        ->middleware('permission:stationery-institutions.delete');

    // The booklists.
    Route::get('stationery/class-kits', [ClassKitController::class, 'index'])
        ->middleware('permission:stationery-kits.view');
    Route::post('stationery/class-kits', [ClassKitController::class, 'store'])
        ->middleware('permission:stationery-kits.create');
    Route::get('stationery/class-kits/{kit}', [ClassKitController::class, 'show'])
        ->middleware('permission:stationery-kits.view');
    Route::match(['put', 'patch'], 'stationery/class-kits/{kit}', [ClassKitController::class, 'update'])
        ->middleware('permission:stationery-kits.edit');
    /**
     * Publishing is what lets the counter sell from a list, so it sits on edit
     * rather than on view — and archiving withdraws a list a shop is working
     * from, which is the destructive act in this register even though the row
     * survives it.
     */
    Route::post('stationery/class-kits/{kit}/publish', [ClassKitController::class, 'publish'])
        ->middleware('permission:stationery-kits.edit');
    Route::post('stationery/class-kits/{kit}/archive', [ClassKitController::class, 'archive'])
        ->middleware('permission:stationery-kits.delete');
    // Next year's list starts as this year's. Creating a kit, so it takes create.
    Route::post('stationery/class-kits/{kit}/roll-forward', [ClassKitController::class, 'rollForward'])
        ->middleware('permission:stationery-kits.create');
    /**
     * The counter's question — what does this list cost, and what is short?
     * Read-only and commits nothing, so it sits on view: the parent is still
     * deciding.
     */
    Route::post('stationery/class-kits/{kit}/estimate', [ClassKitController::class, 'estimate'])
        ->middleware('permission:stationery-kits.view');
    /**
     * Both of these write a core sales document, so they need the authority to
     * raise one — holding the booklists is not the same as being allowed to
     * commit the shop to a four-hundred-set order.
     */
    Route::post('stationery/class-kits/{kit}/quote', [ClassKitController::class, 'quote'])
        ->middleware('permission:sales.create');
    Route::post('stationery/class-kits/{kit}/order', [ClassKitController::class, 'order'])
        ->middleware('permission:sales.create');
    Route::delete('stationery/class-kits/{kit}', [ClassKitController::class, 'destroy'])
        ->middleware('permission:stationery-kits.delete');

    /**
     * The school-supply board. A projection over core sales documents, so it
     * reads on `sales.view` rather than on a permission of its own — the rows
     * *are* sales orders, and hiding them behind a stationery grant would let a
     * shop show someone orders on one screen and not the other.
     */
    Route::get('stationery/school-orders', [SchoolOrderController::class, 'index'])
        ->middleware('permission:sales.view');
    Route::get('stationery/school-quotations', [SchoolOrderController::class, 'quotations'])
        ->middleware('permission:sales.view');
    /**
     * The purchase-order reference arrives days after the order and is a
     * precondition of being paid, so it is editable at any status — refusing it
     * once the goods have gone would leave a bill that can never be settled.
     */
    Route::post('stationery/school-orders/{salesOrder}/po', [SchoolOrderController::class, 'recordPo'])
        ->middleware('permission:sales.edit');
    /**
     * The season's buying list. Behind the booklist permission because reading it
     * is reading the lists — acting on it is a purchase order, raised on the
     * purchase screen by whoever holds that authority.
     */
    Route::get('stationery/season-demand', [SchoolOrderController::class, 'seasonDemand'])
        ->middleware('permission:stationery-kits.view');
});
