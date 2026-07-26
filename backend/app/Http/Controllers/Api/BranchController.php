<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BranchRequest;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function index(Request $request)
    {
        $query = Branch::query()->with('company:id,name');

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->query('company_id'));
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%");
            });
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $sort = $request->query('sort', 'name');
        $direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';
        if (in_array($sort, ['code', 'name', 'city', 'is_active', 'created_at'], true)) {
            $query->orderBy($sort, $direction);
        } else {
            $query->orderBy('name');
        }

        $perPage = min((int) $request->query('per_page', 15), 100);

        return BranchResource::collection($query->paginate($perPage));
    }

    public function store(BranchRequest $request)
    {
        // refresh(): an omitted "country"/"is_active" is set by the column's DB default on
        // INSERT, which the in-memory model doesn't know about yet.
        $branch = Branch::create($request->validated())->refresh();

        return (new BranchResource($branch->load('company:id,name')))->response()->setStatusCode(201);
    }

    public function show(Branch $branch)
    {
        return new BranchResource($branch->load('company:id,name'));
    }

    public function update(BranchRequest $request, Branch $branch)
    {
        $branch->update($request->validated());

        return new BranchResource($branch->load('company:id,name'));
    }

    public function destroy(Branch $branch)
    {
        if ($branch->users()->exists() || User::where('current_branch_id', $branch->id)->exists()) {
            return response()->json(['message' => 'This branch has users assigned and cannot be deleted.'], 422);
        }
        $branch->delete();

        return response()->json(['message' => 'Branch deleted.']);
    }
}
