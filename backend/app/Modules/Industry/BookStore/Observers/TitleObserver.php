<?php

declare(strict_types=1);

namespace App\Modules\Industry\BookStore\Observers;

use App\Models\Product;
use App\Modules\Industry\BookStore\Services\Isbn;
use Illuminate\Validation\ValidationException;

/**
 * Keeps a title's number honest, and makes it the thing the scanner finds.
 *
 * An observer rather than a step in a controller because an ISBN arrives by more
 * routes than the product screen: the generic importer, a channel listing sync,
 * the demo seeder, a purchase that creates the article on receipt. Every one of
 * them must land on the same normalised, checked number, or the register quietly
 * develops two spellings of the same book.
 *
 * Three things happen here, and each of them is a mistake this trade actually
 * makes:
 *
 *  1. **Normalisation.** An ISBN is printed with hyphens and read out with
 *     pauses; it is stored as digits. A shop that stores "978-93-5136-557-0" and
 *     searches "9789351365570" owns a book it cannot find.
 *  2. **The check digit.** Both forms carry one, so a mistyped ISBN can be caught
 *     at the keyboard instead of three weeks later when the wrong book arrives —
 *     and the wrong book *will* arrive, because one wrong digit is usually a
 *     different title that also exists. This is the only validation in the ERP
 *     that can tell a shop it is about to order something it did not mean.
 *  3. **The barcode.** An ISBN-13 *is* the EAN-13 printed on the back cover.
 *     Copying it onto `products.barcode` is what makes a book scannable at the
 *     till without anyone keying thirteen digits a second time — and without the
 *     Book Store module having to reach into the POS at all.
 *
 * The barcode is only ever *adopted*, never overwritten. A shop that has typed
 * its own number onto an article has said something, and a plugin loaded at boot
 * has no business arguing with it — so the copy happens when the field is empty
 * or still holds what core defaulted it to (the SKU), and when the barcode is
 * visibly just following the ISBN it has been given so far.
 */
class TitleObserver
{
    public function creating(Product $product): void
    {
        $this->applyIsbn($product);
    }

    public function updating(Product $product): void
    {
        $this->applyIsbn($product);
    }

    /**
     * @throws ValidationException when a supplied ISBN fails its own check digit
     */
    private function applyIsbn(Product $product): void
    {
        $rawThirteen = $product->book_isbn13;
        $rawTen = $product->book_isbn10;

        // Not a book. The overwhelming majority of saves in a shop that also
        // sells pens and gift wrap, so it costs nothing.
        if (blank($rawThirteen) && blank($rawTen)) {
            return;
        }

        $thirteen = Isbn::toIsbn13($rawThirteen ?: $rawTen);
        $ten = Isbn::toIsbn10($rawTen ?: $rawThirteen);

        /**
         * A number was given and it is not a valid ISBN. Refusing is the whole
         * point: the alternative is a catalogue that looks complete and cannot be
         * ordered from. The key names the field the shop actually typed into, so
         * the error lands under the right box.
         */
        if (filled($rawThirteen) && $thirteen === null) {
            throw ValidationException::withMessages([
                'book_isbn13' => 'This is not a valid ISBN — check the digits. One wrong digit is usually a different book.',
            ]);
        }

        if (filled($rawTen) && Isbn::toIsbn13($rawTen) === null) {
            throw ValidationException::withMessages([
                'book_isbn10' => 'This is not a valid ISBN-10 — check the digits.',
            ]);
        }

        $previousThirteen = $product->getOriginal('book_isbn13');

        $product->book_isbn13 = $thirteen;
        /**
         * The ten-digit form is derived and stored rather than computed on read,
         * because it is what half the supplier files and every pre-2007 backlist
         * still key on — a search has to be able to hit it. Null for a 979-prefix
         * title, which has never had one.
         */
        $product->book_isbn10 = $ten;

        $this->adoptAsBarcode($product, $thirteen, $previousThirteen);
    }

    /**
     * Copy the ISBN onto the barcode, but only where doing so is not overruling
     * the shop.
     */
    private function adoptAsBarcode(Product $product, ?string $thirteen, ?string $previousThirteen): void
    {
        if ($thirteen === null) {
            return;
        }

        $barcode = $product->barcode;

        $isUnset = blank($barcode);
        // Core fills an empty barcode from the SKU on create (Product::booted),
        // and this observer runs after it — so "the SKU" here means "nobody has
        // chosen a barcode", not "the shop chose the SKU".
        $isCoreDefault = filled($product->sku) && $barcode === $product->sku;
        // Already following the ISBN, and the ISBN has just been corrected.
        $wasFollowingIsbn = $previousThirteen !== null && $barcode === $previousThirteen;

        if ($isUnset || $isCoreDefault || $wasFollowingIsbn) {
            $product->barcode = $thirteen;
        }
    }
}
