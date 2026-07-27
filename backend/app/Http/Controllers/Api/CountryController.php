<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CountryRequest;
use App\Http\Resources\CountryResource;
use App\Models\Country;
use Illuminate\Http\Request;

/**
 * Country master (Import & Export Logistics). Referenced by ports, freight
 * forwarders and overseas parties, so a country in use cannot be deleted.
 */
class CountryController extends Controller
{
    public function index(Request $request)
    {
        $query = Country::query();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('iso3', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('currency_code', 'like', "%{$search}%");
            });
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($request->filled('region')) {
            $query->where('region', $request->query('region'));
        }

        $sort = $request->query('sort', 'name');
        $direction = $request->query('direction', 'asc') === 'desc' ? 'desc' : 'asc';
        $query->orderBy(in_array($sort, ['name', 'code', 'region', 'created_at'], true) ? $sort : 'name', $direction);

        $perPage = min((int) $request->query('per_page', 15), 100);

        return CountryResource::collection($query->paginate($perPage));
    }

    public function store(CountryRequest $request)
    {
        $country = Country::create($request->validated());

        return (new CountryResource($country))->response()->setStatusCode(201);
    }

    public function show(Country $country)
    {
        return new CountryResource($country);
    }

    public function update(CountryRequest $request, Country $country)
    {
        $country->update($request->validated());

        return new CountryResource($country);
    }

    public function destroy(Country $country)
    {
        if ($country->ports()->exists()
            || $country->freightForwarders()->exists()
            || $country->suppliers()->exists()
            || $country->customers()->exists()
        ) {
            return response()->json([
                'message' => 'Cannot delete a country that is used by ports, forwarders or overseas parties. Mark it inactive instead.',
            ], 422);
        }
        $country->delete();

        return response()->json(['message' => 'Country deleted.']);
    }
}
