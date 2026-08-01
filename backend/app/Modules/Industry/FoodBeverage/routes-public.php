<?php

declare(strict_types=1);

use App\Modules\Industry\FoodBeverage\Http\Controllers\PublicMenuController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Food & Beverage — public routes
|--------------------------------------------------------------------------
|
| Required from routes/api.php, outside every auth and tenancy middleware: the
| tenant is resolved from the token in the URL, exactly as the pay page does it.
|
| One route, GET only, read-only. That is the whole security model of the QR
| menu: there is no endpoint here that can write, so a guessed or shared token
| exposes a price list and nothing else. Anything that would let a scan place an
| order must be added deliberately, with its own review — not slipped in beside
| this one.
|
*/

Route::get('/menu/{token}', [PublicMenuController::class, 'show']);
