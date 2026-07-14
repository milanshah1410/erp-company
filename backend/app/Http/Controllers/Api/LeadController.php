<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LeadRequest;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\LeadResource;
use App\Models\Customer;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeadController extends Controller
{
    public function index(Request $request)
    {
        $query = Lead::query()->with('assignee:id,name');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('company_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('source')) {
            $query->where('source', $request->query('source'));
        }

        if ($request->filled('assigned_to')) {
            $query->where('assigned_to', $request->query('assigned_to'));
        }

        // "open" = still in the pipeline (not won/lost); "converted" = has a customer.
        if ($request->query('state') === 'open') {
            $query->whereNotIn('status', ['won', 'lost']);
        } elseif ($request->query('state') === 'converted') {
            $query->whereNotNull('converted_customer_id');
        }

        $sort = $request->query('sort', 'created_at');
        $direction = $request->query('direction', 'desc');
        if (in_array($sort, ['name', 'code', 'status', 'expected_close_date', 'created_at'], true)) {
            $query->orderBy($sort, $direction === 'asc' ? 'asc' : 'desc');
        }

        $perPage = min((int) $request->query('per_page', 15), 100);

        return LeadResource::collection($query->paginate($perPage));
    }

    public function store(LeadRequest $request)
    {
        $data = $request->validated();
        $data['code'] = Lead::nextNumber();
        $data['status'] = $data['status'] ?? 'new';
        $data['created_by'] = $request->user()?->id;

        $lead = Lead::create($data);

        return (new LeadResource($lead->load('assignee')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Lead $lead)
    {
        return new LeadResource($lead->load('assignee', 'convertedCustomer:id,code,name'));
    }

    public function update(LeadRequest $request, Lead $lead)
    {
        $lead->update($request->validated());

        return new LeadResource($lead->load('assignee'));
    }

    public function destroy(Lead $lead)
    {
        $lead->followUps()->delete();
        $lead->delete();

        return response()->json(['message' => 'Lead deleted.']);
    }

    /**
     * Convert a lead into a customer. Idempotent: a lead already converted
     * returns the existing customer instead of creating a duplicate. Marks
     * the lead "won" and links it to the new customer.
     */
    public function convert(Request $request, Lead $lead)
    {
        if ($lead->isConverted()) {
            return response()->json([
                'message' => 'This lead has already been converted.',
                'data' => new CustomerResource($lead->convertedCustomer),
            ], 422);
        }

        $validated = $request->validate([
            'type' => ['nullable', 'in:retail,wholesale,distributor'],
            'customer_category_id' => ['nullable', 'integer', 'exists:customer_categories,id'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
        ]);

        $customer = DB::transaction(function () use ($lead, $validated) {
            $customer = Customer::create([
                'code' => $this->nextCustomerCode(),
                'name' => $lead->name,
                'company_name' => $lead->company_name,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'type' => $validated['type'] ?? 'retail',
                'customer_category_id' => $validated['customer_category_id'] ?? null,
                'credit_limit' => $validated['credit_limit'] ?? 0,
                'is_active' => true,
            ]);

            $lead->update([
                'status' => 'won',
                'converted_customer_id' => $customer->id,
                'converted_at' => now(),
            ]);

            // Carry any open follow-ups over to the new customer.
            $lead->followUps()->where('status', 'pending')->update([
                'followupable_type' => Customer::class,
                'followupable_id' => $customer->id,
            ]);

            return $customer;
        });

        return (new CustomerResource($customer))
            ->response()
            ->setStatusCode(201);
    }

    /** Generate the next sequential, collision-free customer code (CUST-000001). */
    private function nextCustomerCode(): string
    {
        $next = (int) (Customer::query()->max('id') ?? 0) + 1;

        do {
            $code = 'CUST-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
            $next++;
        } while (Customer::query()->where('code', $code)->exists());

        return $code;
    }
}
