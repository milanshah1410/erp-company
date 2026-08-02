<?php

declare(strict_types=1);

namespace App\Modules\Industry\BookStore\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Industry\BookStore\Http\Requests\PublisherRequest;
use App\Modules\Industry\BookStore\Models\Publisher;
use Illuminate\Http\Request;

/**
 * The houses the shop buys from, and what it buys on.
 *
 * A small master carrying two decisions that run the business: the trade
 * discount, which *is* the shop's margin because a book is sold at the price on
 * its cover; and whether the house takes stock back, which decides whether a
 * title that has not moved since March is a deadline or a loss.
 */
class PublisherController extends Controller
{
    public function index(Request $request)
    {
        $query = Publisher::query()
            ->withCount('titles')
            ->with('supplier:id,name');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('imprint', 'like', "%{$search}%");
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // The buyer's filter: which houses will take stock back. It is the first
        // question asked when the shelves need clearing.
        if ($request->filled('is_returnable')) {
            $query->where('is_returnable', $request->boolean('is_returnable'));
        }

        $query->orderBy('name');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (Publisher $p) => $this->row($p))->toArray()
        );
    }

    public function show(Publisher $publisher)
    {
        $publisher->loadCount('titles')->load('supplier:id,name');

        return response()->json(['data' => $this->row($publisher)]);
    }

    public function store(PublisherRequest $request)
    {
        $publisher = Publisher::create($request->validated());

        return response()->json(
            ['data' => $this->row($publisher->fresh(['supplier'])->loadCount('titles'))],
            201,
        );
    }

    public function update(PublisherRequest $request, Publisher $publisher)
    {
        $publisher->update($request->validated());

        return response()->json(
            ['data' => $this->row($publisher->fresh(['supplier'])->loadCount('titles'))],
        );
    }

    public function destroy(Publisher $publisher)
    {
        /**
         * Deleting a house with titles behind it would null the publisher on
         * every one of them — quietly detaching a few hundred books from the
         * terms they were bought on and from any hope of sending them back.
         * Deactivating takes it out of the picker and keeps the record, which is
         * what "we don't stock them any more" actually means.
         */
        if ($publisher->titles()->exists()) {
            return response()->json([
                'message' => 'Titles are catalogued under this publisher. Deactivate it instead of deleting it.',
            ], 422);
        }

        $publisher->delete();

        return response()->json(['message' => 'Publisher removed.']);
    }

    private function row(Publisher $p): array
    {
        return [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'imprint' => $p->imprint,
            'display_name' => $p->displayName(),
            'supplier_id' => $p->supplier_id,
            'supplier' => $p->supplier?->name,
            'trade_discount_percent' => $p->trade_discount_percent === null
                ? null
                : (float) $p->trade_discount_percent,
            'is_returnable' => (bool) $p->is_returnable,
            'return_window_days' => $p->return_window_days,
            /**
             * Said in words, because the three states are easy to confuse in a
             * column of numbers and the difference between them is the difference
             * between clearing a shelf and writing it off.
             */
            'returns_terms' => match (true) {
                ! $p->is_returnable => 'Firm sale',
                $p->return_window_days === null => 'Sale or return — open ended',
                default => "Sale or return within {$p->return_window_days} days",
            },
            'contact_person' => $p->contact_person,
            'phone' => $p->phone,
            'email' => $p->email,
            'website' => $p->website,
            'notes' => $p->notes,
            'titles_count' => (int) ($p->titles_count ?? 0),
            'is_active' => (bool) $p->is_active,
        ];
    }
}
