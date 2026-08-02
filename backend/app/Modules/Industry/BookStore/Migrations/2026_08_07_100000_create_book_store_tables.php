<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Book Store plugin schema: what a title *is*, and who is behind it.
 *
 * `.claude/shops-modules.md` §"📚 11. Book Store": ISBN, Author, Publisher,
 * Language, Category, Barcode, Online Orders.
 *
 * Three of those seven are already somebody else's and are deliberately not
 * rebuilt here — Category is core `categories` (a genre is a category and
 * nothing more), Barcode is `inventory`/`devices`, and Online Orders is the
 * `ecommerce` module's channel inbox, which the book-store preset ticks. What is
 * left is the reason a book shop cannot run on an ordinary product master:
 *
 *  - **A title has an identity that nobody in the shop issues.** The ISBN is
 *    assigned by the publisher, is the same thirteen digits in every shop on
 *    earth, and — this is the part that matters — *is* the EAN barcode printed on
 *    the cover. An SKU is what the shop calls a book; an ISBN is what the book
 *    is. The two are not interchangeable and a shop that only has the first
 *    cannot reorder, cannot match a supplier's file and cannot answer the phone.
 *  - **The people on the cover are a register, not a text field.** "What else do
 *    you have by her?" is the most-asked question at a bookshop counter, and a
 *    string column cannot answer it. Nor is there only one of them: a translated
 *    novel has an author *and* a translator, an anthology has an editor, a
 *    children's book has an illustrator whose name sells it.
 *  - **A publisher is a trading counterparty, not a brand.** A brand is a mark on
 *    merchandise. A publisher sets the price printed on the cover, sells at a
 *    trade discount off it, and in this trade alone will often take unsold copies
 *    back for credit. Those are commercial terms, and they belong to a party, not
 *    to a logo. Core `brands` stays free for the stationery and the gifts the same
 *    shop sells.
 *  - **Language is how the shelves are laid out.** In this market a shop stocks
 *    Gujarati, Hindi and English side by side, and the same title exists in all
 *    three as three separate articles at three separate prices.
 *
 * Four registers, then, plus the columns on `products` that carry a title's own
 * bibliographic facts. The ISBN lives on the product rather than in a table of
 * its own because it identifies the article itself — one title, one ISBN, and a
 * different edition is a different article with a different one.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- 1. Publishers -----------------------------------------------------
        Schema::create('book_publishers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 160);

            /**
             * Houses trade under imprints — Penguin sells as Penguin Classics and
             * as Puffin — and the imprint is what is printed on the spine and what
             * a customer asks for. Kept as a field on the house rather than as a
             * parent/child tree because a shop's buying, its discount and its
             * returns are all settled with the house; splitting the register would
             * make every commercial term ambiguous to save a string.
             */
            $table->string('imprint', 120)->nullable();

            /**
             * Who the shop actually buys from. Usually a distributor and not the
             * publisher at all, which is exactly why this is a nullable link to
             * the ordinary supplier register rather than an assumption: the terms
             * below belong to the publisher, the purchase order goes to whoever is
             * pointed at here, and in this trade they routinely differ.
             */
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();

            /**
             * The trade discount off the printed price. This is how the whole
             * trade is priced: a book is sold at the price on the cover and the
             * shop's margin *is* this number, so it is a term of the relationship
             * rather than a per-order negotiation. Stored on the publisher so a
             * buyer can see at a glance which houses are worth stocking.
             */
            $table->decimal('trade_discount_percent', 5, 2)->nullable();

            /**
             * Sale or return — the one commercial fact that makes bookselling
             * different from every other retail trade in this ERP. Unsold copies
             * go back to the publisher for credit inside a window, which means
             * dead stock on a returnable house is a deadline and dead stock on a
             * firm-sale house is a loss. A shop that cannot tell them apart writes
             * off books it could have sent back.
             *
             * The window is in days from the invoice, and is nullable for an
             * open-ended arrangement.
             */
            $table->boolean('is_returnable')->default(false);
            $table->unsignedInteger('return_window_days')->nullable();

            $table->string('contact_person', 120)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('website', 190)->nullable();
            $table->text('notes')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'book_publishers_code_unique');
            $table->index(['tenant_id', 'is_active'], 'book_publishers_active_idx');
        });

        // --- 2. Authors and the rest of the people on the cover ----------------
        Schema::create('book_authors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);

            /**
             * How the shelf is alphabetised — "Tagore, Rabindranath" for a book
             * whose cover says "Rabindranath Tagore". Held rather than derived,
             * because no rule survives contact with real names: particles, single
             * names, honorifics, and scripts that do not have a surname at all.
             * Falls back to `name` when blank.
             */
            $table->string('sort_name', 160)->nullable();

            $table->string('nationality', 80)->nullable();
            // The language they mainly write in — a browsing fact, not a
            // constraint. An author is free to appear on titles in any language.
            $table->string('language', 60)->nullable();
            $table->text('biography')->nullable();
            $table->string('website', 190)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            /**
             * Unique on the name, because the failure this register exists to
             * prevent is the *duplicate*: the same author entered twice splits
             * their shelf in half and the counter answers "what else by her?"
             * with a lie. A shop with two genuinely different people of one name
             * disambiguates in the name itself, which is what a library does too.
             */
            $table->unique(['tenant_id', 'name'], 'book_authors_name_unique');
            $table->index(['tenant_id', 'sort_name'], 'book_authors_sort_idx');
        });

        // --- 3. Languages ------------------------------------------------------
        Schema::create('book_languages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // 'en', 'gu', 'hi' — ISO 639-1 where one exists, the shop's own code
            // where it does not, which is why this is a string and not a lookup
            // against a fixed list. Kutchi and Sindhi are stocked and neither is
            // in anybody's dropdown.
            $table->string('code', 12);
            $table->string('label', 80);
            // "ગુજરાતી" — what the shelf card says, in the language it names.
            $table->string('native_name', 80)->nullable();
            $table->string('script', 60)->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'book_languages_code_unique');
        });

        // --- 4. Who is on which title ------------------------------------------
        Schema::create('book_title_contributors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('book_author_id')->constrained('book_authors')->cascadeOnDelete();

            /**
             * A join table rather than an `author` column on the product, because
             * a title has more than one person on it and they are not
             * interchangeable. The role is the whole point: a translator's name
             * sells a Russian novel in Gujarati, an illustrator's sells a
             * children's book, and a customer asking for "the Nagindas Sanghavi
             * one" may mean any of them.
             */
            $table->enum('role', [
                'author', 'co_author', 'editor', 'translator',
                'illustrator', 'foreword', 'compiler', 'narrator',
            ])->default('author');

            // Billing order on the cover. First-listed is the one the spine and
            // the shelf label use.
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            /**
             * One person may appear twice on a title in two roles (author and
             * illustrator is common in children's books), so the uniqueness runs
             * across the role as well.
             */
            $table->unique(['product_id', 'book_author_id', 'role'], 'book_contributors_unique');
            $table->index(['tenant_id', 'book_author_id'], 'book_contributors_author_idx');
        });

        // --- 5. The title's own facts, on the article ---------------------------
        Schema::table('products', function (Blueprint $table) {
            /**
             * The ISBN, in both the forms a shop meets it in. Thirteen digits is
             * the current one and the only one that is also a barcode; ten is what
             * is printed on everything published before 2007 and what half the
             * supplier files still key on. Both are stored normalised (digits only,
             * trailing X allowed on the ten) so a search matches whether the
             * customer read out the hyphens or not.
             *
             * Indexed on the thirteen because that is what a scanner sends and
             * what a reorder file is matched on. Not unique: a shop legitimately
             * carries the same ISBN twice when it stocks both a new copy and a
             * second-hand one, and refusing that at the database would be a rule
             * nobody in the trade agreed to.
             */
            $table->string('book_isbn13', 13)->nullable();
            $table->string('book_isbn10', 10)->nullable();

            $table->foreignId('book_publisher_id')->nullable()
                ->constrained('book_publishers')->nullOnDelete();
            $table->foreignId('book_language_id')->nullable()
                ->constrained('book_languages')->nullOnDelete();

            /**
             * Binding decides the price, the shelf and often whether the shop
             * stocks it at all — the hardback and the paperback of one title are
             * two different articles a year apart.
             */
            $table->enum('book_binding', [
                'paperback', 'hardcover', 'board_book', 'spiral',
                'leather', 'magazine', 'ebook', 'audiobook', 'other',
            ])->nullable();

            // "3rd edition, revised" / "Reprint 2024". Free text because editions
            // are named by whoever published them and no scheme survives.
            $table->string('book_edition', 80)->nullable();
            $table->unsignedInteger('book_pages')->nullable();
            $table->date('book_published_on')->nullable();

            /**
             * The series and where in it this volume sits. Not decoration: a
             * customer who finished volume three asks for volume four by the
             * series name, and a buyer reorders a series as a set.
             */
            $table->string('book_series', 160)->nullable();
            $table->string('book_volume', 20)->nullable();

            /**
             * The price printed on the cover, which in this trade is not the same
             * question as `selling_price`. The printed price is a fact about the
             * book that the publisher decided and the customer can read; the
             * selling price is what this shop charges today. They are equal until
             * the shop runs 20% off, and the margin is worked out against the
             * printed one via the publisher's trade discount.
             */
            $table->decimal('book_printed_price', 12, 2)->nullable();

            $table->index(['tenant_id', 'book_isbn13'], 'products_isbn13_idx');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_isbn13_idx');
            $table->dropConstrainedForeignId('book_language_id');
            $table->dropConstrainedForeignId('book_publisher_id');
            $table->dropColumn([
                'book_isbn13', 'book_isbn10', 'book_binding', 'book_edition',
                'book_pages', 'book_published_on', 'book_series', 'book_volume',
                'book_printed_price',
            ]);
        });

        Schema::dropIfExists('book_title_contributors');
        Schema::dropIfExists('book_languages');
        Schema::dropIfExists('book_authors');
        Schema::dropIfExists('book_publishers');
    }
};
