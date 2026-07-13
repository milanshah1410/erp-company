<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerAddressRequest;
use App\Http\Resources\CustomerAddressResource;
use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CustomerAddressController extends Controller
{
    public function index(Request $request, Customer $customer)
    {
        $query = $customer->addresses()->orderBy('type')->orderByDesc('is_default')->orderBy('id');

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        return CustomerAddressResource::collection($query->get());
    }

    public function store(CustomerAddressRequest $request, Customer $customer)
    {
        $address = DB::transaction(function () use ($request, $customer) {
            $data = $request->validated();
            $address = $customer->addresses()->create($data);
            $this->enforceSingleDefault($customer, $address);

            return $address;
        });

        return (new CustomerAddressResource($address))
            ->response()
            ->setStatusCode(201);
    }

    public function update(CustomerAddressRequest $request, Customer $customer, CustomerAddress $address)
    {
        $this->assertOwned($customer, $address);

        DB::transaction(function () use ($request, $customer, $address) {
            $address->update($request->validated());
            $this->enforceSingleDefault($customer, $address);
        });

        return new CustomerAddressResource($address->refresh());
    }

    public function destroy(Customer $customer, CustomerAddress $address)
    {
        $this->assertOwned($customer, $address);
        $address->delete();

        return response()->json(['message' => 'Address deleted.']);
    }

    private function assertOwned(Customer $customer, CustomerAddress $address): void
    {
        if ($address->customer_id !== $customer->id) {
            throw new NotFoundHttpException('Address not found for this customer.');
        }
    }

    /** Only one default address per (customer, type). */
    private function enforceSingleDefault(Customer $customer, CustomerAddress $address): void
    {
        if (! $address->is_default) {
            return;
        }

        $customer->addresses()
            ->where('type', $address->type)
            ->whereKeyNot($address->id)
            ->update(['is_default' => false]);
    }
}
