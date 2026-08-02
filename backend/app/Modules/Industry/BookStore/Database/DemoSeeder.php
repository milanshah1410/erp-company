<?php

declare(strict_types=1);

namespace App\Modules\Industry\BookStore\Database;

use App\Models\Product;
use App\Modules\Industry\BookStore\Models\Author;
use App\Modules\Industry\BookStore\Models\BookLanguage;
use App\Modules\Industry\BookStore\Models\Publisher;
use App\Modules\Industry\BookStore\Models\TitleContributor;
use App\Modules\Industry\Contracts\IndustryDemoSeeder;

/**
 * Demo fixtures for the Books & Periodicals vertical, found by convention from
 * IndustryRegistry::demoSeeders() and run by DemoShopSeeder.
 *
 * A demo shop full of tidy English paperbacks demonstrates nothing, because a
 * tidy shelf is not what this vertical is for. What it is for is the cases a
 * product master cannot express, so those are what get seeded:
 *
 *  - **one title in three languages** — the same novel in Gujarati, Hindi and
 *    English, as three articles at three prices with three ISBNs, which is what
 *    the language register exists to make legible;
 *  - **a translated book**, credited to its author *and* its translator, because
 *    the translator's name is what sells it and a single `author` column would
 *    have thrown that away;
 *  - **a picture book whose writer also drew it**, which is the case the
 *    two-roles-one-person key allows on purpose;
 *  - **a returnable house sitting on dead stock**, so the difference between
 *    "send it back by March" and "we own this forever" is visible on one screen;
 *  - **a title whose barcode is not its ISBN**, because somebody typed a shop
 *    code over it — the quiet failure where the till and the catalogue stop
 *    agreeing and nothing looks wrong until a customer is told a book is not in
 *    stock while it is on the shelf.
 *
 * Every ISBN here is genuinely valid: the plugin's observer check-sums them on
 * the way in and would refuse a made-up one, which is itself worth knowing about
 * a demo dataset.
 *
 * Keyed on the module rather than the trade — the general shop and the academic
 * bookshop want the same registers, and the academic one gets its booklists from
 * the Stationery vertical's own seeder.
 *
 * Idempotent throughout: masters are matched on their code or name and titles on
 * their SKU, and the cataloguing is re-applied on every run so that a demo
 * workspace seeded before this vertical existed picks it up.
 */
class DemoSeeder implements IndustryDemoSeeder
{
    /** @param  list<string>  $modules */
    public function seed(array $modules, int $userId): void
    {
        if (! in_array('books', $modules, true)) {
            return;
        }

        $languages = $this->languages();
        $publishers = $this->publishers();
        $authors = $this->authors();

        $titles = $this->titles($languages, $publishers);

        $this->credits($titles, $authors);
    }

    /**
     * The sections. Gujarati first, because this is a Gujarat shop and a shop
     * puts its own language at the front — which is exactly what `sort_order` is
     * for and why the list is not alphabetical.
     *
     * @return array<string, BookLanguage>
     */
    private function languages(): array
    {
        $rows = [
            'gu' => ['code' => 'gu', 'label' => 'Gujarati', 'native_name' => 'ગુજરાતી', 'script' => 'Gujarati', 'sort_order' => 10],
            'hi' => ['code' => 'hi', 'label' => 'Hindi', 'native_name' => 'हिन्दी', 'script' => 'Devanagari', 'sort_order' => 20],
            'en' => ['code' => 'en', 'label' => 'English', 'native_name' => null, 'script' => 'Latin', 'sort_order' => 30],
            'sa' => ['code' => 'sa', 'label' => 'Sanskrit', 'native_name' => 'संस्कृतम्', 'script' => 'Devanagari', 'sort_order' => 40],
        ];

        $out = [];
        foreach ($rows as $key => $attrs) {
            $out[$key] = BookLanguage::firstOrCreate(['code' => $attrs['code']], $attrs);
        }

        return $out;
    }

    /**
     * The houses, in the three commercial shapes a bookseller deals with.
     *
     * @return array<string, Publisher>
     */
    private function publishers(): array
    {
        $rows = [
            // Sale or return with a real deadline: the arrangement that turns
            // slow stock into something a buyer can act on.
            'navbharat' => [
                'code' => 'NAVB', 'name' => 'Navbharat Sahitya Mandir', 'imprint' => null,
                'trade_discount_percent' => 35, 'is_returnable' => true, 'return_window_days' => 180,
                'contact_person' => 'Kishore Shah', 'phone' => '079 2213 4567',
            ],
            // Firm sale, and the best discount in the shop — which is the trade
            // off a buyer is actually making when they choose what to stock.
            'rrsheth' => [
                'code' => 'RRS', 'name' => 'R. R. Sheth & Co.', 'imprint' => null,
                'trade_discount_percent' => 40, 'is_returnable' => false,
                'contact_person' => 'Bhavesh Sheth', 'phone' => '079 2550 1234',
            ],
            // Sale or return, open ended — the third state, and the one that
            // reads as "no date to watch" rather than "no returns".
            'penguin' => [
                'code' => 'PEN', 'name' => 'Penguin Random House India', 'imprint' => 'Penguin Classics',
                'trade_discount_percent' => 32, 'is_returnable' => true, 'return_window_days' => null,
                'contact_person' => 'Distribution desk', 'phone' => '0124 478 5000',
            ],
            // A schoolbook house: low discount, firm sale, and the whole year's
            // trade in ten weeks.
            'vidyarthi' => [
                'code' => 'VID', 'name' => 'Vidyarthi Prakashan', 'imprint' => null,
                'trade_discount_percent' => 22, 'is_returnable' => false,
                'contact_person' => 'Sales office', 'phone' => '079 2657 8899',
            ],
        ];

        $out = [];
        foreach ($rows as $key => $attrs) {
            $out[$key] = Publisher::firstOrCreate(['code' => $attrs['code']], $attrs);
        }

        return $out;
    }

    /**
     * The people on the covers, with the shelf names spelt out — because the
     * whole point of `sort_name` is that it cannot be derived, and a demo where
     * every author is "Firstname Lastname" would never show it.
     *
     * @return array<string, Author>
     */
    private function authors(): array
    {
        $rows = [
            'meghani' => ['name' => 'Jhaverchand Meghani', 'sort_name' => 'Meghani, Jhaverchand', 'nationality' => 'Indian', 'language' => 'Gujarati'],
            'joshi' => ['name' => 'Dhruv Bhatt', 'sort_name' => 'Bhatt, Dhruv', 'nationality' => 'Indian', 'language' => 'Gujarati'],
            'premchand' => ['name' => 'Munshi Premchand', 'sort_name' => 'Premchand, Munshi', 'nationality' => 'Indian', 'language' => 'Hindi'],
            // A translator, in the register on exactly the same footing as an
            // author — which is the point of the role being on the credit rather
            // than on the person.
            'trivedi' => ['name' => 'Harshad Trivedi', 'sort_name' => 'Trivedi, Harshad', 'nationality' => 'Indian', 'language' => 'Gujarati'],
            // Writes and draws her own books: the person who will hold two
            // credits on one title.
            'desai' => ['name' => 'Nilima Desai', 'sort_name' => 'Desai, Nilima', 'nationality' => 'Indian', 'language' => 'Gujarati'],
            'orwell' => ['name' => 'George Orwell', 'sort_name' => 'Orwell, George', 'nationality' => 'British', 'language' => 'English'],
        ];

        $out = [];
        foreach ($rows as $key => $attrs) {
            $out[$key] = Author::firstOrCreate(['name' => $attrs['name']], $attrs);
        }

        return $out;
    }

    /**
     * The stock. Every ISBN below is real arithmetic — the observer would refuse
     * it otherwise, and a demo that quietly disabled its own validation would be
     * demonstrating the wrong thing.
     *
     * Tax is 0% throughout: printed books and periodicals are GST-exempt in
     * India, which surprises people setting a bookshop up and is worth having the
     * demo state plainly.
     *
     * @param  array<string, BookLanguage>  $languages
     * @param  array<string, Publisher>  $publishers
     * @return array<string, Product>
     */
    private function titles(array $languages, array $publishers): array
    {
        $rows = [
            /**
             * One novel, three languages, three articles. The Gujarati original,
             * its Hindi translation and its English one — different ISBNs,
             * different publishers, different prices, and nothing but the
             * language register to tell a browsing customer which shelf to stand
             * at.
             */
            'samudrantike_gu' => [
                'sku' => 'BK-GU-0001', 'name' => 'Samudrantike (સમુદ્રાન્તિકે)',
                'isbn13' => '9789351365570', 'language' => 'gu', 'publisher' => 'navbharat',
                'binding' => 'paperback', 'pages' => 264, 'printed_price' => 350, 'selling_price' => 350,
                'published_on' => '2019-06-01', 'edition' => '12th impression',
            ],
            'samudrantike_hi' => [
                'sku' => 'BK-HI-0001', 'name' => 'Samudrantike (हिन्दी अनुवाद)',
                'isbn13' => '9788179921227', 'language' => 'hi', 'publisher' => 'navbharat',
                'binding' => 'paperback', 'pages' => 248, 'printed_price' => 295, 'selling_price' => 295,
                'published_on' => '2020-02-01',
            ],
            'samudrantike_en' => [
                'sku' => 'BK-EN-0001', 'name' => 'By the Sea (English translation)',
                'isbn13' => '9789386221988', 'language' => 'en', 'publisher' => 'penguin',
                'binding' => 'hardcover', 'pages' => 232, 'printed_price' => 599, 'selling_price' => 539,
                'published_on' => '2021-09-15',
            ],

            // The backlist that sells every year without anyone deciding to
            // stock it.
            'saurashtra' => [
                'sku' => 'BK-GU-0002', 'name' => 'Saurashtra ni Rasdhar — Part 1',
                'isbn13' => '9789380419558', 'language' => 'gu', 'publisher' => 'rrsheth',
                'binding' => 'hardcover', 'pages' => 320, 'printed_price' => 425, 'selling_price' => 425,
                'series' => 'Saurashtra ni Rasdhar', 'volume' => '1',
            ],
            'godan' => [
                'sku' => 'BK-HI-0002', 'name' => 'Godan',
                'isbn13' => '9788179913451', 'language' => 'hi', 'publisher' => 'penguin',
                'binding' => 'paperback', 'pages' => 384, 'printed_price' => 299, 'selling_price' => 269,
            ],

            // The picture book its author also illustrated.
            'chakli' => [
                'sku' => 'BK-GU-0003', 'name' => 'Chakli ane Chokhano Dano',
                'isbn13' => '9789350297117', 'language' => 'gu', 'publisher' => 'navbharat',
                'binding' => 'board_book', 'pages' => 24, 'printed_price' => 180, 'selling_price' => 180,
                'published_on' => '2022-11-01',
            ],

            /**
             * Dead stock on a returnable house. Bought last winter, has not
             * moved, and the 180-day window on Navbharat is the reason it is a
             * decision rather than a loss.
             */
            'kavya' => [
                'sku' => 'BK-GU-0004', 'name' => 'Adhunik Gujarati Kavita Sangrah',
                'isbn13' => '9789382577447', 'language' => 'gu', 'publisher' => 'navbharat',
                'binding' => 'paperback', 'pages' => 196, 'printed_price' => 275, 'selling_price' => 275,
                'published_on' => '2024-12-10',
            ],

            /**
             * The quiet failure. Somebody typed a shop code over the barcode, so
             * the ISBN is right in the catalogue and the scanner finds nothing —
             * the till and the register have stopped agreeing and the shelf looks
             * no different. `barcode_is_isbn` on the Titles screen is what makes
             * it visible.
             */
            'orwell_1984' => [
                'sku' => 'BK-EN-0002', 'name' => 'Nineteen Eighty-Four',
                'isbn13' => '9788175253339', 'language' => 'en', 'publisher' => 'penguin',
                'binding' => 'paperback', 'pages' => 336, 'printed_price' => 399, 'selling_price' => 359,
                'barcode' => 'SHOP-0002',
            ],

            // Schoolbooks: low margin, firm sale, and the whole reason the
            // academic trade exists.
            'science9' => [
                'sku' => 'BK-ED-0001', 'name' => 'Science — Standard 9 (GSEB)',
                'isbn13' => '9789351188209', 'language' => 'gu', 'publisher' => 'vidyarthi',
                'binding' => 'paperback', 'pages' => 288, 'printed_price' => 210, 'selling_price' => 210,
                'edition' => '2026-27 session',
            ],
            'maths10' => [
                'sku' => 'BK-ED-0002', 'name' => 'Mathematics — Standard 10 (GSEB)',
                'isbn13' => '9789387310551', 'language' => 'en', 'publisher' => 'vidyarthi',
                'binding' => 'paperback', 'pages' => 356, 'printed_price' => 245, 'selling_price' => 245,
                'edition' => '2026-27 session',
            ],

            // A periodical, to show that the module is not only about books —
            // and that a magazine is an article with a binding like any other.
            'magazine' => [
                'sku' => 'BK-MG-0001', 'name' => 'Safari — Knowledge Monthly',
                'isbn13' => '9788172349011', 'language' => 'gu', 'publisher' => 'rrsheth',
                'binding' => 'magazine', 'pages' => 68, 'printed_price' => 60, 'selling_price' => 60,
            ],
        ];

        $out = [];
        foreach ($rows as $key => $attrs) {
            $product = Product::firstOrCreate(
                ['sku' => $attrs['sku']],
                [
                    'name' => $attrs['name'],
                    // Printed books and periodicals are GST-exempt in India.
                    'tax_percent' => 0,
                    'is_active' => true,
                ],
            );

            /**
             * Cataloguing is applied on every run, not only at creation. A demo
             * workspace seeded before this vertical existed has the articles and
             * none of the bibliography, and the whole trade is invisible without
             * it. forceFill rather than update() so a re-run also repairs a row
             * whose fields were cleared by hand.
             */
            $product->forceFill([
                'name' => $attrs['name'],
                'book_isbn13' => $attrs['isbn13'],
                'book_language_id' => $languages[$attrs['language']]->id,
                'book_publisher_id' => $publishers[$attrs['publisher']]->id,
                'book_binding' => $attrs['binding'],
                'book_pages' => $attrs['pages'] ?? null,
                'book_edition' => $attrs['edition'] ?? null,
                'book_series' => $attrs['series'] ?? null,
                'book_volume' => $attrs['volume'] ?? null,
                'book_published_on' => $attrs['published_on'] ?? null,
                'book_printed_price' => $attrs['printed_price'],
                'selling_price' => $attrs['selling_price'],
                // Costed at the house's trade discount, which is how a bookshop
                // actually knows what a copy cost it.
                'cost_price' => $publishers[$attrs['publisher']]
                    ->costAtPrintedPrice((float) $attrs['printed_price']),
            ]);

            /**
             * Only the deliberately-wrong one sets a barcode. Everywhere else the
             * observer copies the ISBN onto it, which is the behaviour worth
             * demonstrating — and overriding it here would hide that.
             */
            if (isset($attrs['barcode'])) {
                $product->barcode = $attrs['barcode'];
            }

            $product->save();

            $out[$key] = $product;
        }

        return $out;
    }

    /**
     * Who is on which cover. Rebuilt on every run so a re-seed repairs credits
     * rather than doubling them — the unique key would refuse the duplicate
     * anyway, but a firstOrCreate per row would leave a hand-edited set half
     * corrected.
     *
     * @param  array<string, Product>  $titles
     * @param  array<string, Author>  $authors
     */
    private function credits(array $titles, array $authors): void
    {
        $rows = [
            'samudrantike_gu' => [['joshi', 'author']],
            // The translation: the author *and* the person who made it readable
            // in Hindi. This pair is why the credits are a table.
            'samudrantike_hi' => [['joshi', 'author'], ['trivedi', 'translator']],
            'samudrantike_en' => [['joshi', 'author'], ['trivedi', 'translator']],
            'saurashtra' => [['meghani', 'author']],
            'godan' => [['premchand', 'author']],
            // One person, two roles on one title — allowed on purpose, and the
            // reason the unique key spans the role.
            'chakli' => [['desai', 'author'], ['desai', 'illustrator']],
            'kavya' => [['meghani', 'author'], ['trivedi', 'compiler']],
            'orwell_1984' => [['orwell', 'author']],
        ];

        foreach ($rows as $titleKey => $credits) {
            if (! isset($titles[$titleKey])) {
                continue;
            }

            $product = $titles[$titleKey];

            TitleContributor::where('product_id', $product->id)->delete();

            foreach (array_values($credits) as $index => [$authorKey, $role]) {
                TitleContributor::create([
                    'product_id' => $product->id,
                    'book_author_id' => $authors[$authorKey]->id,
                    'role' => $role,
                    'sort_order' => $index,
                ]);
            }
        }
    }
}
