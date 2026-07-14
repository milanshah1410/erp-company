<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExpenseCategoryRequest;
use App\Http\Resources\ExpenseCategoryResource;
use App\Models\ExpenseCategory;
use Illuminate\Http\Request;

class ExpenseCategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = ExpenseCategory::query()->withCount('expenses');

        if ($search = $request->query('search')) {
            $query->where('name', 'like', "%{$search}%");
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $query->orderBy('name');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return ExpenseCategoryResource::collection($query->paginate($perPage));
    }

    public function store(ExpenseCategoryRequest $request)
    {
        $category = ExpenseCategory::create($request->validated());

        return (new ExpenseCategoryResource($category))
            ->response()
            ->setStatusCode(201);
    }

    public function show(ExpenseCategory $expenseCategory)
    {
        return new ExpenseCategoryResource($expenseCategory->loadCount('expenses'));
    }

    public function update(ExpenseCategoryRequest $request, ExpenseCategory $expenseCategory)
    {
        $expenseCategory->update($request->validated());

        return new ExpenseCategoryResource($expenseCategory);
    }

    public function destroy(ExpenseCategory $expenseCategory)
    {
        if ($expenseCategory->expenses()->exists()) {
            return response()->json(['message' => 'Cannot delete a category that has expenses booked against it.'], 422);
        }
        $expenseCategory->delete();

        return response()->json(['message' => 'Expense category deleted.']);
    }
}
