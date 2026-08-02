<?php

declare(strict_types=1);

use App\Modules\Industry\Mobile\Http\Controllers\BuybackController;
use App\Modules\Industry\Mobile\Http\Controllers\EmiPlanController;
use App\Modules\Industry\Mobile\Http\Controllers\HandsetController;
use App\Modules\Industry\Mobile\Http\Controllers\InsurancePolicyController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile — tenant routes
|--------------------------------------------------------------------------
|
| Required from inside routes/tenant.php's middleware group, so everything here
| already carries tenancy, authentication and the tenant's permission checks. Only
| the per-route permission and module gates are stated.
|
| `module:mobile` on every route. Not decoration: a menu row's module tag gates the
| nav, but a route with no menu row would stay open to the API with the module
| switched off (see EnabledModules::permissionGroupMap).
|
| Two things are deliberately absent.
|
| Warranty cards: those are the core `warranty` module's, and a phone counter uses
| the same /warranties screens a footwear counter does. This plugin only adds the
| handset link, so a card can name a specific IMEI.
|
| Repair job cards: a repair *is* a service job with a handset and possibly a policy
| behind it, worked through the existing /service-jobs endpoints. The plugin adds the
| columns and the claim stamping; there is no second job book.
|
*/

Route::middleware('module:mobile')->group(function () {
    /*
    | The IMEI register.
    |
    | `lookup` first, and deliberately not nested under {handset}: the counter has
    | fifteen digits off the back of a phone, not an id, and half the handsets it is
    | asked about — insured over the counter, brought in for repair — were never in
    | this shop's stock and have no register row until someone makes one.
    */
    Route::get('mobile/handsets/lookup', [HandsetController::class, 'lookup'])
        ->middleware('permission:mobile-handsets.view');
    Route::get('mobile/handsets', [HandsetController::class, 'index'])
        ->middleware('permission:mobile-handsets.view');
    Route::post('mobile/handsets', [HandsetController::class, 'store'])
        ->middleware('permission:mobile-handsets.create');
    Route::get('mobile/handsets/{handset}', [HandsetController::class, 'show'])
        ->middleware('permission:mobile-handsets.view');
    Route::match(['put', 'patch'], 'mobile/handsets/{handset}', [HandsetController::class, 'update'])
        ->middleware('permission:mobile-handsets.edit');
    Route::delete('mobile/handsets/{handset}', [HandsetController::class, 'destroy'])
        ->middleware('permission:mobile-handsets.delete');

    /*
    | The insurance book.
    */
    Route::get('mobile/insurance', [InsurancePolicyController::class, 'index'])
        ->middleware('permission:mobile-insurance.view');
    /**
     * The counter question — "am I covered?" — asked before any of the work that
     * would follow from the answer, so it sits on view. It reports warranty cover
     * alongside policies, which is why it needs nothing beyond this permission: a
     * customer does not distinguish the two, and answering only half the question
     * would spend a once-only policy on a repair the manufacturer owed.
     */
    Route::get('mobile/insurance/check', [InsurancePolicyController::class, 'check'])
        ->middleware('permission:mobile-insurance.view');
    Route::post('mobile/insurance', [InsurancePolicyController::class, 'store'])
        ->middleware('permission:mobile-insurance.create');
    Route::get('mobile/insurance/{policy}', [InsurancePolicyController::class, 'show'])
        ->middleware('permission:mobile-insurance.view');
    Route::match(['put', 'patch'], 'mobile/insurance/{policy}', [InsurancePolicyController::class, 'update'])
        ->middleware('permission:mobile-insurance.edit');
    /**
     * Voiding withdraws cover a customer is relying on, so it sits on delete
     * authority rather than edit: it is the destructive act in this book even though
     * the row survives it.
     */
    Route::post('mobile/insurance/{policy}/void', [InsurancePolicyController::class, 'void'])
        ->middleware('permission:mobile-insurance.delete');
    Route::delete('mobile/insurance/{policy}', [InsurancePolicyController::class, 'destroy'])
        ->middleware('permission:mobile-insurance.delete');

    /*
    | Finance files. The instalment routes are nested under the file because an
    | instalment has no meaning apart from its schedule.
    */
    Route::get('mobile/emi', [EmiPlanController::class, 'index'])
        ->middleware('permission:mobile-emi.view');
    Route::post('mobile/emi', [EmiPlanController::class, 'store'])
        ->middleware('permission:mobile-emi.create');
    /**
     * Collecting is money changing hands on an in-house file, so it needs the
     * authority to take a payment and not merely to edit a schedule. On a
     * finance-company file it only ticks a row — but it is the same screen, and a
     * permission that depended on which kind of file was open would be one nobody
     * could reason about.
     */
    Route::post('mobile/emi/instalments/{instalment}/collect', [EmiPlanController::class, 'collect'])
        ->middleware('permission:payments.create');
    /**
     * Waiving forgives money owed. Deliberately the heavier authority: it is the one
     * action here that makes a receivable disappear without any of it arriving.
     */
    Route::post('mobile/emi/instalments/{instalment}/waive', [EmiPlanController::class, 'waive'])
        ->middleware('permission:mobile-emi.delete');
    Route::get('mobile/emi/{emiPlan}', [EmiPlanController::class, 'show'])
        ->middleware('permission:mobile-emi.view');
    Route::match(['put', 'patch'], 'mobile/emi/{emiPlan}', [EmiPlanController::class, 'update'])
        ->middleware('permission:mobile-emi.edit');
    Route::post('mobile/emi/{emiPlan}/progress', [EmiPlanController::class, 'progress'])
        ->middleware('permission:mobile-emi.edit');
    Route::delete('mobile/emi/{emiPlan}', [EmiPlanController::class, 'destroy'])
        ->middleware('permission:mobile-emi.delete');

    /*
    | Part-exchange.
    */
    Route::get('mobile/buybacks', [BuybackController::class, 'index'])
        ->middleware('permission:mobile-buyback.view');
    Route::post('mobile/buybacks', [BuybackController::class, 'store'])
        ->middleware('permission:mobile-buyback.create');
    Route::get('mobile/buybacks/{buyback}', [BuybackController::class, 'show'])
        ->middleware('permission:mobile-buyback.view');
    Route::match(['put', 'patch'], 'mobile/buybacks/{buyback}', [BuybackController::class, 'update'])
        ->middleware('permission:mobile-buyback.edit');
    /**
     * Accepting commits the shop to a debt — it is holding the customer's handset and
     * owes them its value — so it rides with the authority to write the valuation
     * rather than merely to read the register.
     */
    Route::post('mobile/buybacks/{buyback}/progress', [BuybackController::class, 'progress'])
        ->middleware('permission:mobile-buyback.edit');
    /**
     * Settling posts a tender against an invoice, which is the same act as taking a
     * payment at the counter and is gated the same way.
     */
    Route::post('mobile/buybacks/{buyback}/settle', [BuybackController::class, 'settle'])
        ->middleware('permission:payments.create');
    /**
     * Taking a handset into stock posts an inward movement, so it needs stock
     * authority — this register's own edit permission has no business creating
     * inventory.
     */
    Route::post('mobile/buybacks/{buyback}/stock', [BuybackController::class, 'takeIntoStock'])
        ->middleware('permission:stock.create');
    Route::delete('mobile/buybacks/{buyback}', [BuybackController::class, 'destroy'])
        ->middleware('permission:mobile-buyback.delete');
});
