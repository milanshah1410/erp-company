<?php

declare(strict_types=1);

namespace App\Modules\Industry\BookStore\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Industry\BookStore\Http\Requests\LanguageRequest;
use App\Modules\Industry\BookStore\Models\BookLanguage;
use Illuminate\Http\Request;

/**
 * The languages the shop stocks in — which is to say, the shelf plan.
 *
 * The smallest register in the vertical and the one a browsing customer uses
 * most: in this market the same title exists in Gujarati, Hindi and English as
 * three articles at three prices, on three different shelves, and "where are the
 * Gujarati novels" is a question about the sign above them.
 */
class LanguageController extends Controller
{
    public function index(Request $request)
    {
        $query = BookLanguage::query()->withCount('titles');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('label', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('native_name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Sorted the way the sections are laid out, not alphabetically: a shop
        // puts its own language first.
        $query->orderBy('sort_order')->orderBy('label');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 50), 100))
                ->through(fn (BookLanguage $l) => $this->row($l))->toArray()
        );
    }

    public function show(BookLanguage $language)
    {
        return response()->json(['data' => $this->row($language->loadCount('titles'))]);
    }

    public function store(LanguageRequest $request)
    {
        $language = BookLanguage::create($request->validated());

        return response()->json(['data' => $this->row($language->loadCount('titles'))], 201);
    }

    public function update(LanguageRequest $request, BookLanguage $language)
    {
        $language->update($request->validated());

        return response()->json(['data' => $this->row($language->loadCount('titles'))]);
    }

    public function destroy(BookLanguage $language)
    {
        // Same reasoning as the other two registers: deleting one in use would
        // null the language on every title under it and empty a whole section of
        // the shop without saying so.
        if ($language->titles()->exists()) {
            return response()->json([
                'message' => 'Titles are shelved under this language. Deactivate it instead of deleting it.',
            ], 422);
        }

        $language->delete();

        return response()->json(['message' => 'Language removed.']);
    }

    private function row(BookLanguage $l): array
    {
        return [
            'id' => $l->id,
            'code' => $l->code,
            'label' => $l->label,
            'native_name' => $l->native_name,
            'display' => $l->display(),
            'script' => $l->script,
            'sort_order' => (int) $l->sort_order,
            'titles_count' => (int) ($l->titles_count ?? 0),
            'is_active' => (bool) $l->is_active,
        ];
    }
}
