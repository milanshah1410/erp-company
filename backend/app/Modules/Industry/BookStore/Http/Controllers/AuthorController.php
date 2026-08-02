<?php

declare(strict_types=1);

namespace App\Modules\Industry\BookStore\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Industry\BookStore\Http\Requests\AuthorRequest;
use App\Modules\Industry\BookStore\Models\Author;
use App\Modules\Industry\BookStore\Models\TitleContributor;
use Illuminate\Http\Request;

/**
 * The people on the covers.
 *
 * The register earns its place on one endpoint: `titles`, which answers the
 * question a bookshop counter is asked all day and no product master can answer
 * on its own — *what else do you have by her?* Everything else here exists to
 * keep that answer true, which mostly means keeping the register free of the
 * same writer entered twice.
 */
class AuthorController extends Controller
{
    public function index(Request $request)
    {
        $query = Author::query()->withCount('contributions');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sort_name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        /**
         * Ordered the way the shelves are — by sort name where the shop gave
         * one, by name where it did not. COALESCE rather than two sorts, so an
         * author with no sort name still lands in the right place in the run
         * instead of in a block at the top or the bottom.
         */
        $query->orderByRaw('COALESCE(NULLIF(sort_name, ""), name)');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (Author $a) => $this->row($a))->toArray()
        );
    }

    public function show(Author $author)
    {
        return response()->json(['data' => $this->row($author->loadCount('contributions'))]);
    }

    public function store(AuthorRequest $request)
    {
        $author = Author::create($request->validated());

        return response()->json(['data' => $this->row($author->loadCount('contributions'))], 201);
    }

    public function update(AuthorRequest $request, Author $author)
    {
        $author->update($request->validated());

        return response()->json(['data' => $this->row($author->loadCount('contributions'))]);
    }

    public function destroy(Author $author)
    {
        /**
         * Deleting somebody with credits behind them would cascade those credits
         * away and silently un-attribute every one of their books. A writer the
         * shop no longer stocks is deactivated, not erased — the titles are still
         * on the shelf and still theirs.
         */
        if ($author->contributions()->exists()) {
            return response()->json([
                'message' => 'This person is credited on titles in the catalogue. Deactivate them instead of deleting them.',
            ], 422);
        }

        $author->delete();

        return response()->json(['message' => 'Author removed.']);
    }

    /**
     * Everything this person is credited on, in any role.
     *
     * The counter's question. Grouped by nothing and sorted by title, because the
     * answer is read out loud: "we have the Gujarati one and two of the novels".
     */
    public function titles(Author $author)
    {
        $rows = TitleContributor::query()
            ->where('book_author_id', $author->id)
            ->with([
                'product:id,sku,name,barcode,selling_price,is_active,book_isbn13,book_publisher_id,book_language_id,book_binding,book_series',
                'product.bookPublisher:id,name,imprint',
                'product.bookLanguage:id,label,native_name',
            ])
            ->get()
            // The credit may outlive the article if a product was force-deleted
            // outside the cascade; a missing title is dropped rather than
            // rendered as a blank row.
            ->filter(fn (TitleContributor $c) => $c->product !== null)
            ->sortBy(fn (TitleContributor $c) => (string) $c->product->name)
            ->values()
            ->map(fn (TitleContributor $c) => [
                'product_id' => $c->product_id,
                'title' => $c->product->name,
                'sku' => $c->product->sku,
                'isbn13' => $c->product->book_isbn13,
                'role' => $c->role,
                'role_label' => $c->roleLabel(),
                'publisher' => $c->product->bookPublisher?->name,
                'language' => $c->product->bookLanguage?->display(),
                'binding' => $c->product->book_binding,
                'series' => $c->product->book_series,
                'selling_price' => (float) $c->product->selling_price,
                'is_active' => (bool) $c->product->is_active,
            ]);

        return response()->json([
            'data' => $rows->all(),
            'author' => $this->row($author->loadCount('contributions')),
        ]);
    }

    private function row(Author $a): array
    {
        return [
            'id' => $a->id,
            'name' => $a->name,
            'sort_name' => $a->sort_name,
            'shelf_name' => $a->shelfName(),
            'nationality' => $a->nationality,
            'language' => $a->language,
            'biography' => $a->biography,
            'website' => $a->website,
            // Credits, not titles: one person can hold two on a book they both
            // wrote and illustrated, and the count is used to decide whether the
            // row may be deleted.
            'contributions_count' => (int) ($a->contributions_count ?? 0),
            'is_active' => (bool) $a->is_active,
        ];
    }
}
