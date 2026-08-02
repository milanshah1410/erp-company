<?php

declare(strict_types=1);

namespace App\Modules\Industry\BookStore;

use App\Models\Product;
use App\Modules\Industry\BookStore\Models\BookLanguage;
use App\Modules\Industry\BookStore\Models\Publisher;
use App\Modules\Industry\BookStore\Models\TitleContributor;
use App\Modules\Industry\BookStore\Observers\TitleObserver;
use App\Modules\Industry\Contracts\IndustryPlugin;

/**
 * Books & Periodicals — the shop whose stock is identified by somebody else.
 *
 * `.claude/shops-modules.md` §"📚 11. Book Store": ISBN, Author, Publisher,
 * Language, Category, Barcode, Online Orders.
 *
 * A book shop looks like ordinary retail and is not, for one structural reason:
 * **the shop does not name its own stock.** Every other trade in this ERP invents
 * its SKUs, decides its own descriptions and prices what it likes. A bookshop
 * receives articles that already have a worldwide identity, a price printed on
 * the cover by somebody else, and a set of people on the front that customers
 * ask for by name. Everything this plugin owns follows from that:
 *
 *  - **The ISBN is the article's real name**, and it is *also* its barcode — an
 *    ISBN-13 beginning 978 or 979 is a valid EAN-13, which is why the back cover
 *    already carries one. So the plugin validates it (both forms carry a check
 *    digit, and one wrong digit is a different book that also exists) and copies
 *    it onto `products.barcode`, which is how a title becomes scannable at the
 *    core till without this module touching the POS at all.
 *  - **The people on the cover are a register.** "What else do you have by her?"
 *    is what a bookshop counter is asked all day, and a text column cannot answer
 *    it. Nor is there one of them: a translated novel has an author and a
 *    translator, an anthology an editor, a picture book an illustrator whose name
 *    is the reason it sells.
 *  - **A publisher is a counterparty, not a brand.** It sets the printed price,
 *    sells at a trade discount off it — which *is* the shop's margin — and often
 *    takes unsold copies back for credit. Sale or return is the commercial fact
 *    that separates this trade from every other one here: slow stock is a
 *    deadline rather than a loss, and a buyer who cannot tell a returnable house
 *    from a firm-sale one writes off books that could have been credited.
 *  - **Language is the shelf plan.** The same title in Gujarati, Hindi and
 *    English is three articles at three prices, and the sections are signed in
 *    the languages they hold.
 *
 * Three of the seven bullets are deliberately somebody else's, because a plugin
 * must not offer a shop two answers to one question:
 *
 *  - **Category** is core `categories`. A genre is a category; Fiction →
 *    Literary Fiction is exactly the tree core already has, and a parallel
 *    "genre" register would mean the same book filed twice.
 *  - **Barcode** is `inventory` and `devices` — scanning, and the label printer.
 *    The one book-specific thing about it is that the number is already on the
 *    cover, which the ISBN observer handles.
 *  - **Online Orders** is the `ecommerce` module: the marketplace connectors and
 *    the `channel_orders` inbox that turns a web order into an ordinary sales
 *    order. Bookselling was the first trade to move online and it is the one that
 *    needs this most, so the preset ticks it — but note the module sits in the
 *    Professional plan, so a Starter subscriber gets the catalogue without the
 *    channel inbox. The plan is the ceiling; a plugin never widens it.
 *
 *    Which is also why the Book Store menu group has no second row pointing at
 *    the channel inbox, though the Toy Shop group takes exactly that shortcut to
 *    the gift desk. A second row makes this group's module an owner of that
 *    row's permission, and groupAvailable() passes if *any* owner is enabled —
 *    fine when both modules sit in one tier, an access widening when they do not.
 *    See the note in MenuSeeder.
 */
class BookStorePlugin implements IndustryPlugin
{
    public function key(): string
    {
        return 'books';
    }

    public function label(): string
    {
        return 'Books & Periodicals';
    }

    public function icon(): string
    {
        return 'pi pi-book';
    }

    public function description(): string
    {
        return 'Book shops: ISBN-identified titles, authors and translators, publishers with their trade discount and return terms, and the languages the shelves are laid out in.';
    }

    /**
     * Teach the core product what a book is, without core knowing this plugin
     * exists.
     *
     * The dependency has to point plugin → core, or a workspace that never bought
     * this vertical would still carry publishers. resolveRelationUsing is the
     * seam: the relations are attached at boot, so with the plugin unregistered
     * the `book_` columns are unused nullables and nothing asks for a relation
     * that is not there.
     *
     * Relation names are prefixed `book*` on purpose. Several verticals now hang
     * relations off Product, and an unqualified `publisher` or `language` is
     * exactly the name a later plugin would take without either of them noticing.
     */
    public function boot(): void
    {
        Product::resolveRelationUsing(
            'bookPublisher',
            static fn (Product $product) => $product->belongsTo(Publisher::class, 'book_publisher_id'),
        );
        Product::resolveRelationUsing(
            'bookLanguage',
            static fn (Product $product) => $product->belongsTo(BookLanguage::class, 'book_language_id'),
        );
        Product::resolveRelationUsing(
            'bookContributors',
            static fn (Product $product) => $product
                ->hasMany(TitleContributor::class, 'product_id')
                ->orderBy('sort_order'),
        );

        /**
         * The ISBN is normalised, check-summed and mirrored onto the barcode
         * wherever a product is saved — the importer and a channel sync included,
         * not only this plugin's own screens. That is precisely what an observer
         * is for, and why this is not a step inside a controller.
         */
        Product::observe(TitleObserver::class);
    }

    /**
     * What these trades add on top of the Core ERP set. Selling, buying, stocking
     * and keeping books are core and merged in by ShopProfiles::all().
     *
     * @return array<string, array<string, mixed>>
     */
    public function trades(): array
    {
        return [
            'book_store' => [
                'label' => 'Book Store',
                'icon' => 'pi pi-book',
                'description' => 'General and academic bookshops: ISBN catalogue, authors and translators, publisher trade terms, language sections, counter billing and online orders',
                /**
                 * `devices` is not optional here — a shop with eight thousand
                 * titles cannot find one by typing its name, and the ISBN on the
                 * back cover is a barcode precisely so that it does not have to.
                 * `pos` is the counter, `gst` the billing (books are largely
                 * exempt, which is itself a reason to be on the GST module rather
                 * than guessing), `ecommerce` the online-order inbox the trade was
                 * asked for, and `loyalty` because a bookshop's regulars are the
                 * business.
                 */
                'modules' => ['books', 'pos', 'gst', 'devices', 'ecommerce', 'loyalty'],
                'features' => [
                    'isbn_catalogue' => 'available',
                    'authors' => 'available',
                    'publishers' => 'available',
                    'languages' => 'available',
                    // Delivered by a module this preset ticks rather than by this
                    // plugin — named anyway, because they are what the trade asks
                    // about and a map that listed only our own work reads as a gap.
                    'categories' => 'available',
                    'barcode' => 'available',
                    'online_orders' => 'available',
                    'loyalty' => 'available',
                    /**
                     * Sale or return. The terms are captured on the publisher and
                     * the deadline is derivable from them, but the sweep that
                     * turns "these 40 slow titles are returnable and 3 windows
                     * shut this month" into a returns claim is not built. Declared
                     * rather than implied — it is the most valuable unbuilt thing
                     * in this vertical and a shop should not be sold it twice.
                     */
                    'sale_or_return' => 'planned',
                    /**
                     * Ordering in a title for a named customer and telling them
                     * when it lands. Every part exists separately (a purchase
                     * order, a customer, an SMS) and nothing joins them up.
                     */
                    'title_reservations' => 'planned',
                ],
            ],
            /**
             * The college and school bookshop, which is a different business from
             * the one above even though it sells some of the same books. Its year
             * is a season, its customer is often an institution rather than a
             * reader, and what it needs beside the ISBN catalogue is the
             * Stationery vertical's booklists and school orders — which already
             * exist and are ticked here rather than rebuilt.
             *
             * That is the whole reason this trade is a preset and not code: a
             * bookshop that supplies a school is the Books register plus the
             * Stationery documents, and neither module needed changing to say so.
             */
            'academic_bookshop' => [
                'label' => 'Academic & School Bookshop',
                'icon' => 'pi pi-graduation-cap',
                'description' => 'Textbook and college bookshops: ISBN catalogue by publisher and language, school booklists and institution supply orders, season buying and counter billing',
                'modules' => ['books', 'stationery', 'pos', 'gst', 'devices', 'ecommerce', 'loyalty'],
                'features' => [
                    'isbn_catalogue' => 'available',
                    'authors' => 'available',
                    'publishers' => 'available',
                    'languages' => 'available',
                    'categories' => 'available',
                    'barcode' => 'available',
                    'online_orders' => 'available',
                    // From the Stationery vertical, which this preset ticks.
                    'school_accounts' => 'available',
                    'class_booklists' => 'available',
                    'school_orders' => 'available',
                    'bulk_pricing' => 'available',
                    'season_demand' => 'available',
                    'sale_or_return' => 'planned',
                    'title_reservations' => 'planned',
                ],
            ],
        ];
    }
}
