<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialYearRequest;
use App\Http\Resources\FinancialYearResource;
use App\Models\FinancialYear;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinancialYearController extends Controller
{
    public function index(Request $request)
    {
        $query = FinancialYear::query()->with('company:id,name');

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->query('company_id'));
        }
        if ($request->filled('is_current')) {
            $query->where('is_current', $request->boolean('is_current'));
        }
        if ($request->filled('is_closed')) {
            $query->where('is_closed', $request->boolean('is_closed'));
        }

        $query->orderByDesc('start_date');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return FinancialYearResource::collection($query->paginate($perPage));
    }

    public function store(FinancialYearRequest $request)
    {
        $data = $request->validated();

        $financialYear = DB::transaction(function () use ($data) {
            $year = FinancialYear::create($data)->refresh();
            if ($year->is_current) {
                $this->demoteOtherCurrentYears($year);
            }

            return $year;
        });

        return (new FinancialYearResource($financialYear->load('company:id,name')))
            ->response()->setStatusCode(201);
    }

    public function show(FinancialYear $financialYear)
    {
        return new FinancialYearResource($financialYear->load('company:id,name'));
    }

    public function update(FinancialYearRequest $request, FinancialYear $financialYear)
    {
        $data = $request->validated();

        DB::transaction(function () use ($financialYear, $data) {
            $financialYear->update($data);
            if ($financialYear->is_current) {
                $this->demoteOtherCurrentYears($financialYear);
            }
        });

        return new FinancialYearResource($financialYear->load('company:id,name'));
    }

    public function destroy(FinancialYear $financialYear)
    {
        if ($financialYear->is_closed) {
            return response()->json(['message' => 'A closed financial year cannot be deleted.'], 422);
        }
        $financialYear->delete();

        return response()->json(['message' => 'Financial year deleted.']);
    }

    /**
     * Lock a financial year so no further back-dated postings land in its period.
     */
    public function close(FinancialYear $financialYear)
    {
        $financialYear->update(['is_closed' => true]);

        return new FinancialYearResource($financialYear->load('company:id,name'));
    }

    /**
     * Reopen a previously closed financial year.
     */
    public function reopen(FinancialYear $financialYear)
    {
        $financialYear->update(['is_closed' => false]);

        return new FinancialYearResource($financialYear->load('company:id,name'));
    }

    /**
     * Make this the single working year for its company.
     */
    public function setCurrent(FinancialYear $financialYear)
    {
        DB::transaction(function () use ($financialYear) {
            $financialYear->update(['is_current' => true]);
            $this->demoteOtherCurrentYears($financialYear);
        });

        return new FinancialYearResource($financialYear->load('company:id,name'));
    }

    /**
     * Enforce the single-current invariant: only the given year stays current
     * within its company.
     */
    private function demoteOtherCurrentYears(FinancialYear $financialYear): void
    {
        FinancialYear::query()
            ->where('company_id', $financialYear->company_id)
            ->whereKeyNot($financialYear->id)
            ->where('is_current', true)
            ->update(['is_current' => false]);
    }
}
