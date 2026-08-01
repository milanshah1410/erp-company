<?php

declare(strict_types=1);

use App\Modules\Industry\FoodBeverage\Http\Controllers\RestaurantMenuController;
use App\Modules\Industry\FoodBeverage\Http\Controllers\RestaurantRecipeController;
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
});
