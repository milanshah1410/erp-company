<?php

declare(strict_types=1);

use App\Modules\Industry\FoodBeverage\Http\Controllers\CakeOrderController;
use App\Modules\Industry\FoodBeverage\Http\Controllers\MembershipController;
use App\Modules\Industry\FoodBeverage\Http\Controllers\MenuComboController;
use App\Modules\Industry\FoodBeverage\Http\Controllers\MenuHappyHourController;
use App\Modules\Industry\FoodBeverage\Http\Controllers\MilkCollectionController;
use App\Modules\Industry\FoodBeverage\Http\Controllers\MilkSubscriptionController;
use App\Modules\Industry\FoodBeverage\Http\Controllers\ParlourStockController;
use App\Modules\Industry\FoodBeverage\Http\Controllers\RestaurantMenuController;
use App\Modules\Industry\FoodBeverage\Http\Controllers\RestaurantRecipeController;
use App\Modules\Industry\FoodBeverage\Http\Controllers\TokenQueueController;
use App\Modules\Industry\FoodBeverage\Http\Controllers\WaiterReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Food & Beverage — tenant routes
|--------------------------------------------------------------------------
|
| Required from inside routes/tenant.php's middleware group, so everything here
| already carries tenancy, authentication and the tenant's permission checks.
| Only the per-route permission and module gates are stated.
|
| `module:restaurant` on every route: these screens are meaningless to a shop
| that does not run a kitchen, and the menu rows that reach them are gated the
| same way. A route with no menu row would otherwise stay open to the API even
| with the module switched off (see EnabledModules::permissionGroupMap).
|
*/

Route::middleware('module:restaurant')->group(function () {
    // Recipes — what each dish is made of, and the food cost that falls out.
    Route::get('restaurant-recipes', [RestaurantRecipeController::class, 'index'])
        ->middleware('permission:restaurant-recipes.view');
    Route::post('restaurant-recipes', [RestaurantRecipeController::class, 'store'])
        ->middleware('permission:restaurant-recipes.create');
    Route::get('restaurant-recipes/{restaurantRecipe}', [RestaurantRecipeController::class, 'show'])
        ->middleware('permission:restaurant-recipes.view');
    Route::match(['put', 'patch'], 'restaurant-recipes/{restaurantRecipe}', [RestaurantRecipeController::class, 'update'])
        ->middleware('permission:restaurant-recipes.edit');
    Route::delete('restaurant-recipes/{restaurantRecipe}', [RestaurantRecipeController::class, 'destroy'])
        ->middleware('permission:restaurant-recipes.delete');

    // Menu — the product master seen as a menu card.
    Route::get('restaurant-menu', [RestaurantMenuController::class, 'index'])
        ->middleware('permission:restaurant-menu.view');
    Route::get('restaurant-menu/qr-codes', [RestaurantMenuController::class, 'qrCodes'])
        ->middleware('permission:restaurant-menu.view');
    /**
     * Availability sits on `edit`, not `delete`: marking the fish off is a normal
     * mid-service act for a floor manager, and putting it behind delete-level
     * authority would mean the person who needs it cannot do it.
     */
    Route::post('restaurant-menu/bulk-availability', [RestaurantMenuController::class, 'bulkAvailability'])
        ->middleware('permission:restaurant-menu.edit');
    Route::post('restaurant-menu/{product}/availability', [RestaurantMenuController::class, 'setAvailability'])
        ->middleware('permission:restaurant-menu.edit');
    Route::match(['put', 'patch'], 'restaurant-menu/{product}', [RestaurantMenuController::class, 'update'])
        ->middleware('permission:restaurant-menu.edit');

    // Waiter-wise performance over a date range.
    Route::get('reports/waiter-performance', [WaiterReportController::class, 'performance'])
        ->middleware('permission:reports.view');

    /*
    |--------------------------------------------------------------------------
    | Combos and happy hours — cafe, fast food
    |--------------------------------------------------------------------------
    |
    | Still `module:restaurant`: both price a menu, and a shop with no menu has
    | nothing for them to price. They sit under the Restaurant module rather than
    | under Loyalty because what they change is the *menu* price, not a reward.
    */
    Route::get('menu-combos', [MenuComboController::class, 'index'])
        ->middleware('permission:menu-combos.view');
    Route::post('menu-combos', [MenuComboController::class, 'store'])
        ->middleware('permission:menu-combos.create');
    Route::get('menu-combos/{menuCombo}', [MenuComboController::class, 'show'])
        ->middleware('permission:menu-combos.view');
    Route::match(['put', 'patch'], 'menu-combos/{menuCombo}', [MenuComboController::class, 'update'])
        ->middleware('permission:menu-combos.edit');
    Route::delete('menu-combos/{menuCombo}', [MenuComboController::class, 'destroy'])
        ->middleware('permission:menu-combos.delete');

    /**
     * The live-offers read is on `.view` and is deliberately cheap: the menu card,
     * the POS grid and the QR menu all poll it, and anybody who can see the menu
     * needs to see the price it is actually being sold at.
     */
    Route::get('menu-happy-hours/live', [MenuHappyHourController::class, 'live'])
        ->middleware('permission:menu-happy-hours.view');
    Route::get('menu-happy-hours', [MenuHappyHourController::class, 'index'])
        ->middleware('permission:menu-happy-hours.view');
    Route::post('menu-happy-hours', [MenuHappyHourController::class, 'store'])
        ->middleware('permission:menu-happy-hours.create');
    Route::get('menu-happy-hours/{menuHappyHour}', [MenuHappyHourController::class, 'show'])
        ->middleware('permission:menu-happy-hours.view');
    Route::match(['put', 'patch'], 'menu-happy-hours/{menuHappyHour}', [MenuHappyHourController::class, 'update'])
        ->middleware('permission:menu-happy-hours.edit');
    Route::delete('menu-happy-hours/{menuHappyHour}', [MenuHappyHourController::class, 'destroy'])
        ->middleware('permission:menu-happy-hours.delete');

    /*
    |--------------------------------------------------------------------------
    | Token queue — fast food
    |--------------------------------------------------------------------------
    |
    | On the kitchen's permissions, not the floor's: the board is worked by
    | whoever is calling numbers, which is the same person who works the KDS.
    */
    Route::get('token-queue', [TokenQueueController::class, 'board'])
        ->middleware('permission:kitchen-orders.view');
    Route::get('token-queue/display', [TokenQueueController::class, 'display'])
        ->middleware('permission:kitchen-orders.view');
    Route::get('token-queue/stats', [TokenQueueController::class, 'stats'])
        ->middleware('permission:kitchen-orders.view');
    Route::post('token-queue/{restaurantOrder}/issue', [TokenQueueController::class, 'issue'])
        ->middleware('permission:restaurant-orders.create');
    /**
     * Advancing sits on kitchen `edit`, not on an order permission: calling a
     * number is a kitchen act performed dozens of times an hour, and putting it
     * behind order-editing authority would mean the person at the hatch cannot do
     * their job.
     */
    Route::post('token-queue/{restaurantOrder}/advance', [TokenQueueController::class, 'advance'])
        ->middleware('permission:kitchen-orders.edit');
});

/*
|--------------------------------------------------------------------------
| Memberships — cafe
|--------------------------------------------------------------------------
|
| Gated on `loyalty`, not `restaurant`. A paid club card is a customer-rewards
| feature that happens to be commonest in cafés — a bakery or a sweet mart sells
| them too, and neither runs a kitchen.
*/
Route::middleware('module:loyalty')->group(function () {
    Route::get('membership-plans', [MembershipController::class, 'plans'])
        ->middleware('permission:memberships.view');
    Route::post('membership-plans', [MembershipController::class, 'storePlan'])
        ->middleware('permission:memberships.create');
    Route::match(['put', 'patch'], 'membership-plans/{membershipPlan}', [MembershipController::class, 'updatePlan'])
        ->middleware('permission:memberships.edit');
    Route::delete('membership-plans/{membershipPlan}', [MembershipController::class, 'destroyPlan'])
        ->middleware('permission:memberships.delete');

    Route::get('memberships', [MembershipController::class, 'index'])
        ->middleware('permission:memberships.view');
    Route::post('memberships', [MembershipController::class, 'store'])
        ->middleware('permission:memberships.create');
    Route::post('memberships/{customerMembership}/renew', [MembershipController::class, 'renew'])
        ->middleware('permission:memberships.edit');
    /**
     * Cancelling is on `delete`: it takes an entitlement away from somebody who
     * paid for it, which is a different level of authority from selling one.
     */
    Route::post('memberships/{customerMembership}/cancel', [MembershipController::class, 'cancel'])
        ->middleware('permission:memberships.delete');
    /**
     * The till's lookup — "is this customer a member?" — on `.view`, because
     * anybody who can ring up a sale has to be able to honour a card.
     */
    Route::get('memberships/for-customer/{customer}', [MembershipController::class, 'forCustomer'])
        ->middleware('permission:memberships.view');
});

/*
|--------------------------------------------------------------------------
| Cake orders — bakery
|--------------------------------------------------------------------------
|
| `module:sales`. A cake order is a forward sale with a deposit; a bakery runs no
| kitchen module and gating this on `restaurant` would hide it from exactly the
| trade it was built for.
*/
Route::middleware('module:sales')->group(function () {
    Route::get('cake-orders', [CakeOrderController::class, 'index'])
        ->middleware('permission:cake-orders.view');
    Route::post('cake-orders', [CakeOrderController::class, 'store'])
        ->middleware('permission:cake-orders.create');
    Route::get('cake-orders/{cakeOrder}', [CakeOrderController::class, 'show'])
        ->middleware('permission:cake-orders.view');
    Route::match(['put', 'patch'], 'cake-orders/{cakeOrder}', [CakeOrderController::class, 'update'])
        ->middleware('permission:cake-orders.edit');
    Route::delete('cake-orders/{cakeOrder}', [CakeOrderController::class, 'destroy'])
        ->middleware('permission:cake-orders.delete');
    // Moving the board along is an ordinary shift act, so it is `edit`.
    Route::post('cake-orders/{cakeOrder}/status', [CakeOrderController::class, 'setStatus'])
        ->middleware('permission:cake-orders.edit');
    Route::post('cake-orders/{cakeOrder}/reference', [CakeOrderController::class, 'uploadReference'])
        ->middleware('permission:cake-orders.edit');
    /**
     * Handover raises an invoice, so it is gated on being allowed to raise a sale
     * — the same authority a POS bill needs, not merely cake-order editing.
     */
    Route::post('cake-orders/{cakeOrder}/deliver', [CakeOrderController::class, 'deliver'])
        ->middleware('permission:sales.create');
});

/*
|--------------------------------------------------------------------------
| Dairy — milk collection and household subscriptions
|--------------------------------------------------------------------------
|
| Collection is a purchase from farmers, so it is gated on `purchase`;
| subscriptions are a standing sale, so they are gated on `sales`. The dairy trade
| preset switches both on, and neither screen appears for a shop that does not buy
| or does not sell on standing order.
*/
Route::middleware('module:purchase')->group(function () {
    Route::get('milk-rate-charts', [MilkCollectionController::class, 'charts'])
        ->middleware('permission:milk-collections.view');
    Route::post('milk-rate-charts', [MilkCollectionController::class, 'storeChart'])
        ->middleware('permission:milk-collections.create');
    Route::match(['put', 'patch'], 'milk-rate-charts/{milkRateChart}', [MilkCollectionController::class, 'updateChart'])
        ->middleware('permission:milk-collections.edit');
    Route::delete('milk-rate-charts/{milkRateChart}', [MilkCollectionController::class, 'destroyChart'])
        ->middleware('permission:milk-collections.delete');

    Route::get('milk-collections', [MilkCollectionController::class, 'index'])
        ->middleware('permission:milk-collections.view');
    Route::post('milk-collections', [MilkCollectionController::class, 'store'])
        ->middleware('permission:milk-collections.create');
    // A live preview while the counter types — read-only, so `.view`.
    Route::post('milk-collections/preview', [MilkCollectionController::class, 'preview'])
        ->middleware('permission:milk-collections.view');
    Route::get('milk-collections/farmer-summary', [MilkCollectionController::class, 'farmerSummary'])
        ->middleware('permission:milk-collections.view');
    /**
     * Settlement raises a purchase invoice against a farmer, so it needs
     * purchase-invoice authority rather than collection-entry authority — the
     * counter keys cans, the office pays for them.
     */
    Route::post('milk-collections/settle', [MilkCollectionController::class, 'settle'])
        ->middleware('permission:purchase-invoices.create');
    Route::delete('milk-collections/{milkCollection}', [MilkCollectionController::class, 'destroy'])
        ->middleware('permission:milk-collections.delete');
});

Route::middleware('module:sales')->group(function () {
    Route::get('milk-subscriptions', [MilkSubscriptionController::class, 'index'])
        ->middleware('permission:milk-subscriptions.view');
    Route::post('milk-subscriptions', [MilkSubscriptionController::class, 'store'])
        ->middleware('permission:milk-subscriptions.create');
    Route::match(['put', 'patch'], 'milk-subscriptions/{milkSubscription}', [MilkSubscriptionController::class, 'update'])
        ->middleware('permission:milk-subscriptions.edit');
    Route::delete('milk-subscriptions/{milkSubscription}', [MilkSubscriptionController::class, 'destroy'])
        ->middleware('permission:milk-subscriptions.delete');

    /**
     * The round sheet, on its own `milk-round` permission rather than the
     * subscription's. The person who walks the round has to be able to correct
     * what was left at a door; they have no business changing what a household is
     * charged, and one permission covering both would have forced a dairy to hand
     * out standing-order rights to every delivery boy.
     */
    Route::get('milk-round', [MilkSubscriptionController::class, 'roundSheet'])
        ->middleware('permission:milk-round.view');
    Route::post('milk-round/generate', [MilkSubscriptionController::class, 'generateRound'])
        ->middleware('permission:milk-round.create');
    Route::post('milk-round/{delivery}/record', [MilkSubscriptionController::class, 'recordDelivery'])
        ->middleware('permission:milk-round.edit');
    // Month end raises invoices, so it is gated on raising a sale.
    Route::post('milk-subscriptions/bill', [MilkSubscriptionController::class, 'bill'])
        ->middleware('permission:sales.create');
});

/*
|--------------------------------------------------------------------------
| Freezers and flavours — ice cream
|--------------------------------------------------------------------------
|
| `module:inventory`: this is a projection over stock, locations and batches, and
| a parlour that has switched Inventory off has nothing for it to read.
*/
Route::middleware('module:inventory')->group(function () {
    Route::get('parlour/freezers', [ParlourStockController::class, 'freezers'])
        ->middleware('permission:stock.view');
    Route::get('parlour/flavours', [ParlourStockController::class, 'flavours'])
        ->middleware('permission:stock.view');
    Route::get('parlour/locations', [ParlourStockController::class, 'locations'])
        ->middleware('permission:stock.view');
    /**
     * Marking a location as a freezer edits a warehouse location, so it carries
     * the warehouse permission rather than a stock one.
     */
    Route::post('parlour/freezers/{warehouseLocation}', [ParlourStockController::class, 'setFreezer'])
        ->middleware('permission:warehouses.edit');
});
