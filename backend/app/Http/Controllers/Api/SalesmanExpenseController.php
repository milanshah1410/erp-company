<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalesmanExpenseRequest;
use App\Http\Resources\SalesmanExpenseResource;
use App\Models\SalesmanExpense;
use Illuminate\Http\Request;

/**
 * Daily field expenses claimed by a salesman (travel, food, fuel, …) with a
 * light approval workflow: pending → approved / rejected.
 */
class SalesmanExpenseController extends Controller
{
    public function index(Request $request)
    {
        $query = SalesmanExpense::query()->with('salesman:id,name,code');

        if ($request->filled('salesman_id')) {
            $query->where('salesman_id', $request->query('salesman_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('category')) {
            $query->where('category', $request->query('category'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('expense_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('expense_date', '<=', $request->query('date_to'));
        }

        $query->orderByDesc('expense_date')->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return SalesmanExpenseResource::collection($query->paginate($perPage));
    }

    public function store(SalesmanExpenseRequest $request)
    {
        $expense = SalesmanExpense::create([
            ...$request->validated(),
            'category' => $request->validated()['category'] ?? 'misc',
            'status' => 'pending',
            'created_by' => $request->user()?->id,
        ]);

        return (new SalesmanExpenseResource($expense->load('salesman')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(SalesmanExpense $salesmanExpense)
    {
        return new SalesmanExpenseResource($salesmanExpense->load('salesman'));
    }

    public function update(SalesmanExpenseRequest $request, SalesmanExpense $salesmanExpense)
    {
        if ($salesmanExpense->status === 'approved') {
            return response()->json(['message' => 'Approved expenses cannot be edited.'], 422);
        }
        // Editing a rejected claim returns it to pending for re-review.
        $salesmanExpense->update([
            ...$request->validated(),
            'status' => 'pending',
            'rejection_reason' => null,
        ]);

        return new SalesmanExpenseResource($salesmanExpense->load('salesman'));
    }

    public function destroy(SalesmanExpense $salesmanExpense)
    {
        if ($salesmanExpense->status === 'approved') {
            return response()->json(['message' => 'Approved expenses cannot be deleted.'], 422);
        }
        $salesmanExpense->delete();

        return response()->json(['message' => 'Expense deleted.']);
    }

    public function approve(Request $request, SalesmanExpense $salesmanExpense)
    {
        if ($salesmanExpense->status !== 'pending') {
            return response()->json(['message' => 'Only pending expenses can be approved.'], 422);
        }
        $salesmanExpense->update([
            'status' => 'approved',
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
        ]);

        return new SalesmanExpenseResource($salesmanExpense->load('salesman'));
    }

    public function reject(Request $request, SalesmanExpense $salesmanExpense)
    {
        if ($salesmanExpense->status !== 'pending') {
            return response()->json(['message' => 'Only pending expenses can be rejected.'], 422);
        }
        $data = $request->validate(['rejection_reason' => ['nullable', 'string', 'max:1000']]);
        $salesmanExpense->update([
            'status' => 'rejected',
            'rejection_reason' => $data['rejection_reason'] ?? null,
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
        ]);

        return new SalesmanExpenseResource($salesmanExpense->load('salesman'));
    }
}
