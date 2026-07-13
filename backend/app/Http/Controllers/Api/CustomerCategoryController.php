<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerCategoryRequest;
use App\Http\Resources\CustomerCategoryResource;
use App\Models\CustomerCategory;
use Illuminate\Http\Request;

class CustomerCategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = CustomerCategory::query()->withCount('customers');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $sort = $request->query('sort', 'name');
        $direction = $request->query('direction', 'asc');
        if (in_array($sort, ['name', 'created_at'], true)) {
            $query->orderBy($sort, $direction === 'desc' ? 'desc' : 'asc');
        }

        $perPage = min((int) $request->query('per_page', 15), 100);

        return CustomerCategoryResource::collection($query->paginate($perPage));
    }

    public function store(CustomerCategoryRequest $request)
    {
        $category = CustomerCategory::create($request->validated());

        return (new CustomerCategoryResource($category))
            ->response()
            ->setStatusCode(201);
    }

    public function show(CustomerCategory $customerCategory)
    {
        return new CustomerCategoryResource($customerCategory->loadCount('customers'));
    }

    public function update(CustomerCategoryRequest $request, CustomerCategory $customerCategory)
    {
        $customerCategory->update($request->validated());

        return new CustomerCategoryResource($customerCategory->loadCount('customers'));
    }

    public function destroy(CustomerCategory $customerCategory)
    {
        if ($customerCategory->customers()->exists()) {
            return response()->json([
                'message' => 'This category has customers assigned and cannot be deleted. Reassign them first.',
            ], 422);
        }
        $customerCategory->delete();

        return response()->json(['message' => 'Customer category deleted.']);
    }
}
