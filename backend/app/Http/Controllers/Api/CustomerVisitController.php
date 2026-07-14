<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerVisitRequest;
use App\Http\Resources\CustomerVisitResource;
use App\Models\Customer;
use App\Models\CustomerVisit;
use Illuminate\Http\Request;

class CustomerVisitController extends Controller
{
    /** Visit history for a customer, newest first. */
    public function index(Request $request, Customer $customer)
    {
        $perPage = min((int) $request->query('per_page', 15), 100);

        $visits = $customer->visits()
            ->with('user:id,name')
            ->orderByDesc('visited_at')
            ->paginate($perPage);

        return CustomerVisitResource::collection($visits);
    }

    public function store(CustomerVisitRequest $request, Customer $customer)
    {
        $data = $request->validated();
        $data['user_id'] = $data['user_id'] ?? $request->user()?->id;

        $visit = $customer->visits()->create($data);

        return (new CustomerVisitResource($visit->load('user')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(CustomerVisitRequest $request, Customer $customer, CustomerVisit $visit)
    {
        abort_unless($visit->customer_id === $customer->id, 404);

        $visit->update($request->validated());

        return new CustomerVisitResource($visit->load('user'));
    }

    public function destroy(Customer $customer, CustomerVisit $visit)
    {
        abort_unless($visit->customer_id === $customer->id, 404);

        $visit->delete();

        return response()->json(['message' => 'Visit deleted.']);
    }
}
