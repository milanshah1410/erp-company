<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalesmanCollectionRequest;
use App\Http\Resources\SalesmanCollectionResource;
use App\Models\SalesmanCollection;
use Illuminate\Http\Request;

/**
 * Collection Entry: payments a salesman collects from customers in the field.
 * A collection starts "collected" and is marked "deposited" once handed in to
 * the office/bank; cheques that fail are marked "bounced".
 */
class SalesmanCollectionController extends Controller
{
    public function index(Request $request)
    {
        $query = SalesmanCollection::query()
            ->with(['salesman:id,name,code', 'customer:id,name']);

        if ($request->filled('salesman_id')) {
            $query->where('salesman_id', $request->query('salesman_id'));
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->query('customer_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('mode')) {
            $query->where('mode', $request->query('mode'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('collection_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('collection_date', '<=', $request->query('date_to'));
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('collection_number', 'like', "%{$search}%")
                    ->orWhere('reference_no', 'like', "%{$search}%");
            });
        }

        $query->orderByDesc('collection_date')->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return SalesmanCollectionResource::collection($query->paginate($perPage));
    }

    public function store(SalesmanCollectionRequest $request)
    {
        $data = $request->validated();

        $collection = SalesmanCollection::create([
            ...$data,
            'collection_number' => SalesmanCollection::nextNumber(),
            'mode' => $data['mode'] ?? 'cash',
            'collection_date' => $data['collection_date'] ?? now()->toDateString(),
            'status' => 'collected',
            'created_by' => $request->user()?->id,
        ]);

        return (new SalesmanCollectionResource($collection->load(['salesman', 'customer'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(SalesmanCollection $salesmanCollection)
    {
        return new SalesmanCollectionResource($salesmanCollection->load(['salesman', 'customer']));
    }

    public function update(SalesmanCollectionRequest $request, SalesmanCollection $salesmanCollection)
    {
        if ($salesmanCollection->status !== 'collected') {
            return response()->json([
                'message' => 'Only collections that are still with the salesman can be edited.',
            ], 422);
        }
        $salesmanCollection->update($request->validated());

        return new SalesmanCollectionResource($salesmanCollection->load(['salesman', 'customer']));
    }

    public function destroy(SalesmanCollection $salesmanCollection)
    {
        if ($salesmanCollection->status === 'deposited') {
            return response()->json([
                'message' => 'Deposited collections cannot be deleted. Cancel it instead.',
            ], 422);
        }
        $salesmanCollection->delete();

        return response()->json(['message' => 'Collection deleted.']);
    }

    /** Mark a collection as deposited to the office/bank. */
    public function deposit(SalesmanCollection $salesmanCollection)
    {
        if ($salesmanCollection->status !== 'collected') {
            return response()->json([
                'message' => 'Only collected entries can be deposited.',
            ], 422);
        }
        $salesmanCollection->update([
            'status' => 'deposited',
            'deposited_at' => now(),
        ]);

        return new SalesmanCollectionResource($salesmanCollection->load(['salesman', 'customer']));
    }

    /** Mark a (cheque) collection as bounced. */
    public function bounce(SalesmanCollection $salesmanCollection)
    {
        if (! in_array($salesmanCollection->status, ['collected', 'deposited'], true)) {
            return response()->json([
                'message' => 'Only collected or deposited entries can be bounced.',
            ], 422);
        }
        $salesmanCollection->update([
            'status' => 'bounced',
            'deposited_at' => null,
        ]);

        return new SalesmanCollectionResource($salesmanCollection->load(['salesman', 'customer']));
    }
}
