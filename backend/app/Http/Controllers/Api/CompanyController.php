<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function index(Request $request)
    {
        $query = Company::query()->withCount('branches');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%");
            });
        }

        $query->orderBy('name');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return CompanyResource::collection($query->paginate($perPage));
    }

    public function store(CompanyRequest $request)
    {
        // refresh(): omitted optional fields (currency, financial year, etc.) are set by the
        // column's DB default on INSERT, which the in-memory model doesn't know about yet.
        $company = Company::create($request->validated())->refresh();

        return (new CompanyResource($company->loadCount('branches')))->response()->setStatusCode(201);
    }

    public function show(Company $company)
    {
        return new CompanyResource($company->loadCount('branches'));
    }

    public function update(CompanyRequest $request, Company $company)
    {
        $company->update($request->validated());

        return new CompanyResource($company->loadCount('branches'));
    }

    public function destroy(Company $company)
    {
        if ($company->branches()->exists()) {
            return response()->json(['message' => 'This company has branches and cannot be deleted. Delete its branches first.'], 422);
        }
        if ($company->users()->exists() || User::where('current_company_id', $company->id)->exists()) {
            return response()->json(['message' => 'This company has users assigned and cannot be deleted.'], 422);
        }
        $company->delete();

        return response()->json(['message' => 'Company deleted.']);
    }
}
