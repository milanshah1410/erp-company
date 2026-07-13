<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = Category::query()->with(['parent']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $sort = $request->query('sort', 'created_at');
        $direction = $request->query('direction', 'desc');
        if (in_array($sort, ['name', 'created_at'], true)) {
            $query->orderBy($sort, $direction === 'asc' ? 'asc' : 'desc');
        }

        $perPage = min((int) $request->query('per_page', 15), 100);

        return CategoryResource::collection($query->paginate($perPage));
    }

    public function store(CategoryRequest $request)
    {
        $category = Category::create($request->validated());

        return (new CategoryResource($category->load(['parent'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Category $category)
    {
        return new CategoryResource($category->load(['parent', 'children']));
    }

    public function update(CategoryRequest $request, Category $category)
    {
        $category->update($request->validated());

        return new CategoryResource($category->load(['parent']));
    }

    public function destroy(Category $category)
    {
        if ($category->children()->exists()) {
            return response()->json([
                'message' => 'This category has sub-categories. Move or delete them first.',
            ], 422);
        }
        $category->delete();

        return response()->json(['message' => 'Category deleted.']);
    }

    /**
     * Effective attribute definitions for a category: its own plus every
     * ancestor's, with the nearest definition winning on name clashes.
     * Used by the product form to render dynamic attribute fields.
     */
    public function attributes(Category $category)
    {
        $defs = [];
        $node = $category;
        $depth = 0;

        while ($node && $depth++ < 25) { // depth guard against data cycles
            foreach ($node->getAttribute('attributes') ?? [] as $def) {
                if (! isset($defs[$def['name']])) {
                    $defs[$def['name']] = $def; // nearest (deepest) definition wins
                }
            }
            $node = $node->parent;
        }

        return response()->json(['data' => array_values($defs)]);
    }

    /**
     * Full category hierarchy as a nested tree (for the tree UI).
     * Includes per-node product counts and attribute definitions.
     */
    public function tree()
    {
        $categories = Category::query()
            ->withCount('products')
            ->orderBy('name')
            ->get(['id', 'parent_id', 'name', 'slug', 'description', 'attributes', 'is_active']);

        $byParent = $categories->groupBy(fn ($c) => $c->parent_id ?? 0);

        $build = function ($parentId) use (&$build, $byParent) {
            return ($byParent[$parentId] ?? collect())->map(fn ($c) => [
                'id' => $c->id,
                'parent_id' => $c->parent_id,
                'name' => $c->name,
                'slug' => $c->slug,
                'description' => $c->description,
                'attributes' => $c->getAttribute('attributes') ?? [],
                'is_active' => (bool) $c->is_active,
                'products_count' => $c->products_count,
                'children' => $build($c->id)->values(),
            ]);
        };

        return response()->json(['data' => $build(0)->values()]);
    }
}
