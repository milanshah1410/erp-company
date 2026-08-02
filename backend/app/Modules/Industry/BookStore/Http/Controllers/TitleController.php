<?php

declare(strict_types=1);

namespace App\Modules\Industry\BookStore\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Modules\Industry\BookStore\Http\Requests\ContributorsRequest;
use App\Modules\Industry\BookStore\Models\TitleContributor;
use App\Modules\Industry\BookStore\Services\Isbn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The catalogue, read as a bookshop reads it.
 *
 * There is no `titles` table and there should not be: a title is a product, and
 * making it anything else would cost the vertical stock, purchasing, pricing,
 * the till and the label printer. What this controller adds is the *view* — the
 * same rows, indexed by the things a bookshop actually searches on (an ISBN, a
 * publisher, a language, a person on the cover) rather than by SKU.
 *
 * Two endpoints here are the vertical's whole point:
 *
 *  - **lookup** takes an ISBN in whatever form it was read out, hyphens and all,
 *    and says whether the shop has it. It is what the counter uses when a
 *    customer phones, and what receiving uses when a carton arrives with a
 *    delivery note and no order.
 *  - **contributors** replaces the set of people credited on a title. A replace
 *    and not a patch, because that is how the editor behaves — the shop sees
 *    every credit and presses Save.
 */
class TitleController extends Controller
{
    /**
     * Binding, in the words a shop uses. Kept beside the screen that offers it;
     * the column itself is an enum in the plugin migration, and core's
     * ProductRequest restates the same list because it must not depend on a
     * vertical that may not be installed.
     *
     * @var array<string, string>
     */
    public const BINDINGS = [
        'paperback' => 'Paperback',
        'hardcover' => 'Hardcover',
        'board_book' => 'Board book',
        'spiral' => 'Spiral bound',
        'leather' => 'Leather bound',
        'magazine' => 'Magazine / periodical',
        'ebook' => 'E-book',
        'audiobook' => 'Audiobook',
        'other' => 'Other',
    ];

    public function index(Request $request)
    {
        $query = Product::query()
            ->with([
                'bookPublisher:id,name,imprint',
                'bookLanguage:id,label,native_name',
                'bookContributors.author:id,name,sort_name',
            ])
            ->withSum('stocks as stock_quantity', 'quantity')
            ->where(fn ($q) => $this->onlyTitles($q));

        if ($search = trim((string) $request->query('search'))) {
            /**
             * One box, four ways of asking. A customer reads out an ISBN with
             * hyphens, a member of staff types half a title, and a supplier's
             * note quotes the series — all three arrive in the same field, so the
             * search normalises the number *and* keeps the plain text match.
             */
            $isbn = Isbn::normalize($search);

            $query->where(function ($q) use ($search, $isbn) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('book_series', 'like', "%{$search}%")
                    ->orWhere('book_isbn13', 'like', "%{$search}%")
                    ->orWhere('book_isbn10', 'like', "%{$search}%");

                if ($isbn !== null) {
                    $q->orWhere('book_isbn13', $isbn)
                        ->orWhere('book_isbn10', $isbn)
                        ->orWhere('book_isbn13', Isbn::toIsbn13($isbn))
                        ->orWhere('barcode', $isbn);
                }
            });
        }

        foreach (['book_publisher_id' => 'publisher_id', 'book_language_id' => 'language_id'] as $column => $param) {
            if ($request->filled($param)) {
                $query->where($column, $request->integer($param));
            }
        }

        if ($request->filled('binding')) {
            $query->where('book_binding', $request->query('binding'));
        }

        // Every title this person is on, in any role — the shelf-talker query.
        if ($request->filled('author_id')) {
            $query->whereIn('id', TitleContributor::query()
                ->select('product_id')
                ->where('book_author_id', $request->integer('author_id')));
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $query->orderBy('name');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (Product $p) => $this->row($p))->toArray()
                + ['roles' => TitleContributor::ROLES, 'bindings' => self::BINDINGS]
        );
    }

    public function show(Product $product)
    {
        $product->load([
            'bookPublisher:id,name,imprint,trade_discount_percent,is_returnable,return_window_days',
            'bookLanguage:id,label,native_name',
            'bookContributors.author:id,name,sort_name',
        ]);

        return response()->json([
            'data' => $this->row($product),
            'roles' => TitleContributor::ROLES,
            'bindings' => self::BINDINGS,
        ]);
    }

    /**
     * Is this ISBN in the catalogue?
     *
     * Deliberately answers even when it is not: the useful reply to "we don't
     * have it" includes whether the number was valid at all, because half the
     * time it was misread and the shop does have the book. The normalised forms
     * come back too, so receiving can paste the right one into a new article.
     */
    public function lookup(Request $request)
    {
        $raw = (string) $request->query('isbn', '');
        $normalized = Isbn::normalize($raw);
        $thirteen = Isbn::toIsbn13($raw);

        if ($normalized === null || $thirteen === null) {
            return response()->json([
                'query' => $raw,
                'valid' => false,
                'isbn13' => null,
                'isbn10' => null,
                'data' => null,
                'message' => $normalized === null
                    ? 'That is not the right length for an ISBN — 10 or 13 digits.'
                    : 'The check digit does not match. One digit has been misread, so this is not the number on the book.',
            ], 200);
        }

        $product = Product::query()
            ->with([
                'bookPublisher:id,name,imprint',
                'bookLanguage:id,label,native_name',
                'bookContributors.author:id,name,sort_name',
            ])
            ->withSum('stocks as stock_quantity', 'quantity')
            ->where(function ($q) use ($thirteen, $normalized) {
                $q->where('book_isbn13', $thirteen)
                    ->orWhere('book_isbn10', Isbn::toIsbn10($thirteen))
                    ->orWhere('book_isbn10', $normalized)
                    // The barcode too: a title catalogued before this module was
                    // switched on has its ISBN there and nowhere else.
                    ->orWhere('barcode', $thirteen);
            })
            ->first();

        return response()->json([
            'query' => $raw,
            'valid' => true,
            'isbn13' => $thirteen,
            'isbn10' => Isbn::toIsbn10($thirteen),
            'data' => $product ? $this->row($product) : null,
            'message' => $product ? null : 'A valid ISBN, but no title in the catalogue carries it yet.',
        ]);
    }

    /**
     * Replace the people credited on a title.
     *
     * In a transaction because a half-applied replace would leave the book
     * crediting nobody — and the delete has to happen before the insert, since
     * the same person may be moving from one role to another and the unique key
     * spans both.
     */
    public function contributors(ContributorsRequest $request, Product $product)
    {
        $rows = $request->validated()['contributors'];

        DB::transaction(function () use ($product, $rows) {
            TitleContributor::where('product_id', $product->id)->delete();

            foreach (array_values($rows) as $index => $row) {
                TitleContributor::create([
                    'product_id' => $product->id,
                    'book_author_id' => $row['book_author_id'],
                    'role' => $row['role'],
                    // Falls back to the order they were sent in, which is the
                    // order the editor showed them — the billing on the cover.
                    'sort_order' => $row['sort_order'] ?? $index,
                ]);
            }
        });

        $product->load(['bookPublisher:id,name,imprint', 'bookLanguage:id,label,native_name', 'bookContributors.author:id,name,sort_name']);

        return response()->json([
            'data' => $this->row($product),
            'message' => 'Credits saved.',
        ]);
    }

    /**
     * What counts as a title.
     *
     * A book shop that also sells pens keeps both in one product master, so the
     * catalogue screen has to draw a line: anything carrying a book fact — a
     * number, a publisher, a language — or anybody credited on it. Deliberately
     * generous, because the failure that matters is a title *missing* from this
     * list, not a notebook appearing in it.
     */
    private function onlyTitles(Builder $query): void
    {
        $query->whereNotNull('book_isbn13')
            ->orWhereNotNull('book_isbn10')
            ->orWhereNotNull('book_publisher_id')
            ->orWhereNotNull('book_language_id')
            ->orWhereIn('id', TitleContributor::query()->select('product_id'));
    }

    private function row(Product $p): array
    {
        $contributors = $p->relationLoaded('bookContributors')
            ? $p->bookContributors
                ->filter(fn (TitleContributor $c) => $c->author !== null)
                ->map(fn (TitleContributor $c) => [
                    'id' => $c->id,
                    'book_author_id' => $c->book_author_id,
                    'name' => $c->author->name,
                    'sort_name' => $c->author->sort_name,
                    'role' => $c->role,
                    'role_label' => $c->roleLabel(),
                    'sort_order' => (int) $c->sort_order,
                ])->values()->all()
            : [];

        $publisher = $p->relationLoaded('bookPublisher') ? $p->bookPublisher : null;

        return [
            'id' => $p->id,
            'sku' => $p->sku,
            'name' => $p->name,
            'barcode' => $p->barcode,
            'isbn13' => $p->book_isbn13,
            'isbn10' => $p->book_isbn10,
            // Whether the scanner will find this book. False is worth showing:
            // it means somebody typed a different barcode over the ISBN, and the
            // till and the catalogue have quietly stopped agreeing.
            'barcode_is_isbn' => $p->book_isbn13 !== null && $p->barcode === $p->book_isbn13,
            'publisher_id' => $p->book_publisher_id,
            'publisher' => $publisher?->displayName(),
            'language_id' => $p->book_language_id,
            'language' => $p->relationLoaded('bookLanguage') ? $p->bookLanguage?->display() : null,
            'binding' => $p->book_binding,
            'binding_label' => $p->book_binding ? (self::BINDINGS[$p->book_binding] ?? $p->book_binding) : null,
            'edition' => $p->book_edition,
            'pages' => $p->book_pages,
            'published_on' => $p->book_published_on?->toDateString(),
            'series' => $p->book_series,
            'volume' => $p->book_volume,
            'printed_price' => $p->book_printed_price === null ? null : (float) $p->book_printed_price,
            'selling_price' => (float) $p->selling_price,
            /**
             * What the shop is giving away against the price on the cover. The
             * customer can read that price, so a discount here is visible to them
             * whether or not the shop meant to offer one.
             */
            'discount_off_printed' => $this->discountOffPrinted($p),
            'contributors' => $contributors,
            'stock_quantity' => (float) ($p->stock_quantity ?? 0),
            'is_active' => (bool) $p->is_active,
        ];
    }

    private function discountOffPrinted(Product $p): ?float
    {
        $printed = $p->book_printed_price === null ? null : (float) $p->book_printed_price;

        if (! $printed) {
            return null;
        }

        return round((($printed - (float) $p->selling_price) / $printed) * 100, 2);
    }
}
