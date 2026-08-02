<?php

declare(strict_types=1);

use App\Modules\Industry\BookStore\Http\Controllers\AuthorController;
use App\Modules\Industry\BookStore\Http\Controllers\LanguageController;
use App\Modules\Industry\BookStore\Http\Controllers\PublisherController;
use App\Modules\Industry\BookStore\Http\Controllers\TitleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Books & Periodicals — tenant routes
|--------------------------------------------------------------------------
|
| Required from inside routes/tenant.php's middleware group, so everything here
| already carries tenancy, authentication and the tenant's permission checks.
| Only the per-route permission and module gates are stated.
|
| `module:books` on every route, the read-only lookups included: a menu row
| hidden from the nav is not a closed door, and these endpoints would otherwise
| stay open to a hand-typed URL in a workspace that never bought the vertical
| (see EnabledModules::permissionGroupMap).
|
| One permission across four screens. The publishers, the authors, the languages
| and the titles they hang off are one book to whoever keeps it — the person who
| decides which house a title belongs to is the person who spells the
| translator's name and shelves it under Gujarati. Four grants that are always
| given together are one grant with extra steps.
|
| There are no routes here for genres, barcode labels or online orders, and that
| is the point — see the note in BookStorePlugin about which module already owns
| each of them.
|
*/

Route::middleware('module:books')->group(function () {
    // --- Publishers --------------------------------------------------------
    Route::get('books/publishers', [PublisherController::class, 'index'])
        ->middleware('permission:book-catalogue.view');
    Route::post('books/publishers', [PublisherController::class, 'store'])
        ->middleware('permission:book-catalogue.create');
    Route::get('books/publishers/{publisher}', [PublisherController::class, 'show'])
        ->middleware('permission:book-catalogue.view');
    Route::match(['put', 'patch'], 'books/publishers/{publisher}', [PublisherController::class, 'update'])
        ->middleware('permission:book-catalogue.edit');
    Route::delete('books/publishers/{publisher}', [PublisherController::class, 'destroy'])
        ->middleware('permission:book-catalogue.delete');

    // --- Authors -----------------------------------------------------------
    Route::get('books/authors', [AuthorController::class, 'index'])
        ->middleware('permission:book-catalogue.view');
    Route::post('books/authors', [AuthorController::class, 'store'])
        ->middleware('permission:book-catalogue.create');
    /**
     * Declared before the {author} route so "titles" is never taken for an id.
     * It is also the endpoint the counter actually uses — everything else in
     * this register exists to keep its answer true.
     */
    Route::get('books/authors/{author}/titles', [AuthorController::class, 'titles'])
        ->middleware('permission:book-catalogue.view');
    Route::get('books/authors/{author}', [AuthorController::class, 'show'])
        ->middleware('permission:book-catalogue.view');
    Route::match(['put', 'patch'], 'books/authors/{author}', [AuthorController::class, 'update'])
        ->middleware('permission:book-catalogue.edit');
    Route::delete('books/authors/{author}', [AuthorController::class, 'destroy'])
        ->middleware('permission:book-catalogue.delete');

    // --- Languages ---------------------------------------------------------
    Route::get('books/languages', [LanguageController::class, 'index'])
        ->middleware('permission:book-catalogue.view');
    Route::post('books/languages', [LanguageController::class, 'store'])
        ->middleware('permission:book-catalogue.create');
    Route::get('books/languages/{language}', [LanguageController::class, 'show'])
        ->middleware('permission:book-catalogue.view');
    Route::match(['put', 'patch'], 'books/languages/{language}', [LanguageController::class, 'update'])
        ->middleware('permission:book-catalogue.edit');
    Route::delete('books/languages/{language}', [LanguageController::class, 'destroy'])
        ->middleware('permission:book-catalogue.delete');

    /**
     * The catalogue itself.
     *
     * Note there is no create or delete here: a title *is* a product, and it is
     * created, priced and retired on the Products screen like everything else the
     * shop sells. What lives here is the bookshop's way of reading the same rows
     * — and the credits, which is the only part of a title this module owns
     * outright.
     *
     * `books/lookup` is kept off the `books/titles/...` prefix on purpose, so
     * there is no route in which the word could be mistaken for a product id.
     */
    Route::get('books/titles', [TitleController::class, 'index'])
        ->middleware('permission:book-catalogue.view');
    Route::get('books/titles/{product}', [TitleController::class, 'show'])
        ->middleware('permission:book-catalogue.view');
    Route::match(['put', 'patch'], 'books/titles/{product}/contributors', [TitleController::class, 'contributors'])
        ->middleware('permission:book-catalogue.edit');

    /**
     * The counter's lookup: an ISBN in whatever form it was read out, and
     * whether the shop has it. On the register's view permission, because
     * whoever may be asked the question is whoever is standing at the till.
     */
    Route::get('books/lookup', [TitleController::class, 'lookup'])
        ->middleware('permission:book-catalogue.view');
});
