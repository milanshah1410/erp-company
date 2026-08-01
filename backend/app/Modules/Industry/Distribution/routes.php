<?php

declare(strict_types=1);

use App\Modules\Industry\Distribution\Http\Controllers\CreditControlController;
use App\Modules\Industry\Distribution\Http\Controllers\ReceivablesReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Distribution & Wholesale — tenant routes
|--------------------------------------------------------------------------
|
| Required from inside routes/tenant.php's middleware group, so tenancy,
| authentication and permission resolution are already in force here. Only the
| per-route permission and module gates are stated.
|
| `module:distribution` on everything, matching the tag on the menu rows that
| lead here (a route whose row is hidden but whose URL still answers is not
| gated at all). The module is a catalogue key like any other, so the plan
| ceiling and the operator's per-tenant grants both still apply on every read —
| the plugin pre-ticks it for its trades and cannot widen what a package sells.
|
| Deliberately not `module:sales`: these screens are the vertical, not the sales
| ledger they read. A plain retail counter has invoices and no use for a supply
| hold, and gating on Sales would have handed the whole vertical to every
| workspace that bills anything.
|
*/

Route::middleware('module:distribution')->group(function () {
    // Credit Control — party limits, live exposure and the supply hold.
    Route::get('credit-control', [CreditControlController::class, 'index'])
        ->middleware('permission:credit-control.view');
    Route::get('credit-control/filters', [CreditControlController::class, 'filters'])
        ->middleware('permission:credit-control.view');
    /**
     * Policy before the wildcard show route, or 'policy' resolves as a customer
     * id and 404s. Editing it sits on `edit` rather than on `settings`: it is the
     * credit desk's own lever, and the person who holds this screen is the person
     * who should be turning it.
     */
    Route::put('credit-control/policy', [CreditControlController::class, 'updatePolicy'])
        ->middleware('permission:credit-control.edit');
    Route::get('credit-control/{customer}', [CreditControlController::class, 'show'])
        ->middleware('permission:credit-control.view');
    Route::match(['put', 'patch'], 'credit-control/{customer}/terms', [CreditControlController::class, 'updateTerms'])
        ->middleware('permission:credit-control.edit');
    /**
     * Hold and release are both `edit`, not `delete`. Stopping a party's supply is
     * an ordinary Monday-morning act for a credit controller, and putting it
     * behind delete-level authority would mean the person whose job it is cannot
     * do it — the same reasoning as marking a dish sold out in the F&B plugin.
     */
    Route::post('credit-control/{customer}/hold', [CreditControlController::class, 'hold'])
        ->middleware('permission:credit-control.edit');
    Route::post('credit-control/{customer}/release', [CreditControlController::class, 'release'])
        ->middleware('permission:credit-control.edit');

    // Outstanding & Ageing. On `reports.view` like every other report, so a
    // collections clerk who may read the book cannot also change limits.
    Route::get('reports/receivables-ageing', [ReceivablesReportController::class, 'ageing'])
        ->middleware('permission:reports.view');
    Route::get('reports/receivables-ageing/{customer}', [ReceivablesReportController::class, 'party'])
        ->middleware('permission:reports.view');
});
