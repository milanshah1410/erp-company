<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceContractRequest;
use App\Http\Resources\ServiceContractResource;
use App\Models\ServiceContract;
use Illuminate\Http\Request;

/**
 * Annual maintenance contracts. A live contract makes the jobs booked under it
 * non-billable and draws down a visit quota; expiry is derived from the term
 * rather than swept by a job, so a contract is never wrong between runs.
 */
class ServiceContractController extends Controller
{
    public function index(Request $request)
    {
        $query = ServiceContract::query()
            ->with('customer:id,name,phone')
            ->withCount('jobs');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('contract_number', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }
        foreach (['status', 'customer_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }
        // Renewal chasing: everything running out inside the window.
        if ($request->filled('expiring_within_days')) {
            $query->where('status', 'active')
                ->whereBetween('end_date', [
                    now()->toDateString(),
                    now()->addDays((int) $request->query('expiring_within_days'))->toDateString(),
                ]);
        }

        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return ServiceContractResource::collection($query->paginate($perPage));
    }

    public function store(ServiceContractRequest $request)
    {
        $data = $request->validated();

        $contract = ServiceContract::create($data + [
            'contract_number' => ServiceContract::nextNumber(),
            'visits_allowed' => $data['visits_allowed'] ?? 0,
            'amount' => $data['amount'] ?? 0,
            'status' => $data['status'] ?? 'active',
            'created_by' => $request->user()?->id,
        ]);

        return (new ServiceContractResource($contract->load('customer:id,name,phone')))
            ->response()->setStatusCode(201);
    }

    public function show(ServiceContract $serviceContract)
    {
        return new ServiceContractResource(
            $serviceContract->load('customer:id,name,phone')->loadCount('jobs'),
        );
    }

    public function update(ServiceContractRequest $request, ServiceContract $serviceContract)
    {
        $data = $request->validated();

        // visits_used is a ledger of jobs booked, not an editable field — letting
        // it be posted would let a contract's quota drift from its job history.
        $serviceContract->update($data);

        return new ServiceContractResource($serviceContract->load('customer:id,name,phone'));
    }

    public function destroy(ServiceContract $serviceContract)
    {
        if ($serviceContract->jobs()->exists()) {
            return response()->json([
                'message' => 'Jobs have been booked against this contract. Cancel it instead of deleting it.',
            ], 422);
        }

        $serviceContract->delete();

        return response()->json(['message' => 'Contract deleted.']);
    }
}
