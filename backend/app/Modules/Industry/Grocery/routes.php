<?php

declare(strict_types=1);

use App\Modules\Industry\Grocery\Http\Controllers\ComboPackController;
use App\Modules\Industry\Grocery\Http\Controllers\SelfCheckoutController;
use App\Modules\Industry\Grocery\Http\Controllers\ShelfController;
use App\Modules\Industry\Grocery\Http\Controllers\SupplierCreditController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Grocery & Kirana — tenant routes
|--------------------------------------------------------------------------
|
| Required from inside routes/tenant.php's middleware group, so tenancy,
| authentication and permission resolution are already in force. Only the
| per-route permission and module gates are stated here.
|
| `module:grocery` on everything, matching the tag on the menu rows that lead
| here — a route whose nav row is hidden but whose URL still answers is not
| gated at all. Deliberately not `module:purchase` on the supplier-credit half:
| it reads the purchase ledger but it is not the purchase ledger, and gating it
| that way would hand the vertical to every workspace that buys anything.
|
*/

Route::middleware('module:grocery')->group(function () {
    /**
     * Combo packs. On `products.*` rather than a permission of their own: a pack
     * is a product with a statement of what it breaks into, and whoever may set
     * a price may set a bundle price. Inventing `combo-packs.edit` would have
     * meant a shop granting its counter manager the product master and then
     * wondering why the hamper screen was read-only.
     */
    Route::get('combo-packs', [ComboPackController::class, 'index'])
        ->middleware('permission:products.view');
    Route::post('combo-packs', [ComboPackController::class, 'store'])
        ->middleware('permission:products.create');
    Route::get('combo-packs/{comboPack}', [ComboPackController::class, 'show'])
        ->middleware('permission:products.view');
    Route::match(['put', 'patch'], 'combo-packs/{comboPack}', [ComboPackController::class, 'update'])
        ->middleware('permission:products.edit');
    Route::delete('combo-packs/{comboPack}', [ComboPackController::class, 'destroy'])
        ->middleware('permission:products.delete');

    /**
     * Supplier credit. Its own permission, because this is the shop's debt: the
     * counter hand who prices a hamper has no business reading what the owner
     * owes to whom, and the person who does is usually the owner.
     *
     * The literal segments come before the wildcard show route, or 'payment-plan'
     * resolves as a supplier id and 404s.
     */
    Route::get('supplier-credit', [SupplierCreditController::class, 'index'])
        ->middleware('permission:supplier-credit.view');
    Route::get('supplier-credit/payment-plan', [SupplierCreditController::class, 'paymentPlan'])
        ->middleware('permission:supplier-credit.view');
    Route::put('supplier-credit/default-terms', [SupplierCreditController::class, 'updateDefaultTerms'])
        ->middleware('permission:supplier-credit.edit');
    Route::get('supplier-credit/{supplier}', [SupplierCreditController::class, 'show'])
        ->middleware('permission:supplier-credit.view');
    /**
     * Recording a distributor's limit is an ordinary Tuesday act for whoever
     * keeps the khata, so it sits on `edit` rather than on anything heavier —
     * the same reasoning that puts a supply hold on `credit-control.edit`.
     */
    Route::match(['put', 'patch'], 'supplier-credit/{supplier}/terms', [SupplierCreditController::class, 'updateTerms'])
        ->middleware('permission:supplier-credit.edit');
});

/*
|--------------------------------------------------------------------------
| Supermarket — the shelf, and the unattended lane
|--------------------------------------------------------------------------
|
| `module:supermarket`, not `module:grocery`. Both trades live in this vertical
| and a kirana counter has neither aisles nor self-checkout — gating these on the
| vertical's own key would have put a facing count in front of every corner shop.
|
| Two things a reader might look for here and not find:
|
|  - **The till counters and cash-up.** Core, `module:pos` (routes/tenant.php).
|    Reconciling a drawer per desk is what any shop with two tills does, so it
|    would have been wrong to sell a grocery module to get it.
|  - **Coupons, gift cards and membership.** All three are Loyalty & Offers in
|    core, and a supermarket gets them by ticking `loyalty` like anyone else.
|    Batch, expiry and stock transfer are likewise Inventory, unchanged.
*/
Route::middleware('module:supermarket')->group(function () {
    /*
    | The shop floor. Reads sit on `stock.view` and layout changes on
    | `shelf-planogram.*`, because the two are different jobs: a picker reads the
    | floor all day and never re-lays it, and the person who decides that the
    | biscuits move to aisle four is doing merchandising, not stock control.
    */
    Route::get('shelves', [ShelfController::class, 'index'])
        ->middleware('permission:stock.view');
    Route::get('shelves/gaps', [ShelfController::class, 'gaps'])
        ->middleware('permission:stock.view');
    Route::get('shelves/expiring', [ShelfController::class, 'expiring'])
        ->middleware('permission:stock.view');
    Route::get('shelves/{location}/planogram', [ShelfController::class, 'planogram'])
        ->middleware('permission:stock.view');
    Route::get('shelves/{location}/labels', [ShelfController::class, 'labels'])
        ->middleware('permission:barcodes.view');
    Route::match(['put', 'patch'], 'shelves/{location}', [ShelfController::class, 'markShelf'])
        ->middleware('permission:shelf-planogram.edit');

    Route::post('shelf-placements', [ShelfController::class, 'store'])
        ->middleware('permission:shelf-planogram.create');
    Route::match(['put', 'patch'], 'shelf-placements/{shelfPlacement}', [ShelfController::class, 'update'])
        ->middleware('permission:shelf-planogram.edit');
    Route::delete('shelf-placements/{shelfPlacement}', [ShelfController::class, 'destroy'])
        ->middleware('permission:shelf-planogram.delete');
    /**
     * Filling a facing is on `stock.edit`, deliberately not on the planogram
     * permission: it moves stock, and the authority to move stock should not
     * depend on which screen happens to be doing it. A shelf-filler is given
     * stock edit and nothing else; they walk the round, they do not redesign it.
     */
    Route::post('shelf-placements/{shelfPlacement}/replenish', [ShelfController::class, 'replenish'])
        ->middleware('permission:stock.edit');

    /*
    | The self-checkout bank.
    |
    | The lane's login holds `self-checkout.create` and can scan; it must not
    | hold `.approve`, which is the entire mechanism by which an age-restricted
    | item stops the basket until a supervisor walks over. Granting a lane both
    | turns the check off without turning any switch off.
    */
    Route::get('self-checkout/carts', [SelfCheckoutController::class, 'board'])
        ->middleware('permission:self-checkout.view');
    Route::post('self-checkout/carts', [SelfCheckoutController::class, 'store'])
        ->middleware('permission:self-checkout.create');
    Route::get('self-checkout/carts/{selfCheckoutCart}', [SelfCheckoutController::class, 'show'])
        ->middleware('permission:self-checkout.view');
    Route::post('self-checkout/carts/{selfCheckoutCart}/scan', [SelfCheckoutController::class, 'scan'])
        ->middleware('permission:self-checkout.create');
    /**
     * Voiding is on `.approve`, not `.create`. Anybody who can silently unscan an
     * item can put it in their bag and walk out with it, so removing a line is a
     * supervisor act at every real self-checkout bank there is.
     */
    Route::post('self-checkout/carts/{selfCheckoutCart}/items/{item}/void', [SelfCheckoutController::class, 'void'])
        ->middleware('permission:self-checkout.approve');
    Route::post('self-checkout/carts/{selfCheckoutCart}/approve', [SelfCheckoutController::class, 'approve'])
        ->middleware('permission:self-checkout.approve');
    /**
     * Recording the bill is on `sales.create` — the authority to turn goods into
     * money — rather than on a lane permission, because that is what the step is.
     */
    Route::post('self-checkout/carts/{selfCheckoutCart}/complete', [SelfCheckoutController::class, 'complete'])
        ->middleware('permission:sales.create');
    Route::post('self-checkout/carts/{selfCheckoutCart}/abandon', [SelfCheckoutController::class, 'abandon'])
        ->middleware('permission:self-checkout.approve');
});
