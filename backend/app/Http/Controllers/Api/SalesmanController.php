<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalesmanRequest;
use App\Http\Resources\SalesmanCollectionResource;
use App\Http\Resources\SalesmanLocationResource;
use App\Http\Resources\SalesmanResource;
use App\Models\Salesman;
use App\Services\SalesmanIncentiveService;
use Illuminate\Http\Request;

class SalesmanController extends Controller
{
    public function index(Request $request)
    {
        $query = Salesman::query()->with('branch:id,name');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%")
                    ->orWhere('territory', 'like', "%{$search}%");
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->query('branch_id'));
        }

        $sort = $request->query('sort', 'created_at');
        $direction = $request->query('direction', 'desc') === 'asc' ? 'asc' : 'desc';
        if (in_array($sort, ['name', 'code', 'created_at'], true)) {
            $query->orderBy($sort, $direction);
        }

        $perPage = min((int) $request->query('per_page', 15), 100);

        return SalesmanResource::collection($query->paginate($perPage));
    }

    public function store(SalesmanRequest $request)
    {
        $salesman = Salesman::create($request->validated());

        return (new SalesmanResource($salesman->load('branch')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Salesman $salesman)
    {
        return new SalesmanResource($salesman->load(['branch', 'latestLocation']));
    }

    public function update(SalesmanRequest $request, Salesman $salesman)
    {
        $salesman->update($request->validated());

        return new SalesmanResource($salesman->load('branch'));
    }

    public function destroy(Salesman $salesman)
    {
        if ($salesman->orders()->exists()
            || $salesman->collections()->exists()
            || $salesman->visits()->exists()) {
            return response()->json([
                'message' => 'This salesman has orders, collections or visit history and cannot be deleted. Mark it inactive instead.',
            ], 422);
        }
        $salesman->delete();

        return response()->json(['message' => 'Salesman deleted.']);
    }

    /**
     * Target achievement and computed incentive for a salesman in a period
     * (defaults to the current month). Drives the "Incentive Calculation" and
     * "Salesman Target" dashboard screens.
     */
    public function performance(Request $request, Salesman $salesman, SalesmanIncentiveService $incentives)
    {
        $year = (int) $request->query('year', now()->year);
        $month = (int) $request->query('month', now()->month);

        return response()->json([
            'salesman' => new SalesmanResource($salesman),
            'performance' => $incentives->achievement($salesman, $year, $month),
        ]);
    }

    /** The salesman's live (latest) location, for the tracking map. */
    public function liveLocation(Salesman $salesman)
    {
        $location = $salesman->latestLocation()->first();

        return response()->json([
            'data' => $location ? new SalesmanLocationResource($location) : null,
        ]);
    }

    /** Payment collections recorded by this salesman, newest first. */
    public function collections(Request $request, Salesman $salesman)
    {
        $perPage = min((int) $request->query('per_page', 15), 100);

        return SalesmanCollectionResource::collection(
            $salesman->collections()->with('customer:id,name')->orderByDesc('id')->paginate($perPage)
        );
    }
}
