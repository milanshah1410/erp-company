<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BiometricDeviceRequest;
use App\Http\Resources\BiometricDeviceResource;
use App\Models\BiometricDevice;
use Illuminate\Http\Request;

class BiometricDeviceController extends Controller
{
    public function index(Request $request)
    {
        $query = BiometricDevice::query();

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $query->orderBy('name');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return BiometricDeviceResource::collection($query->paginate($perPage));
    }

    public function store(BiometricDeviceRequest $request)
    {
        $device = BiometricDevice::create($request->validated());

        return (new BiometricDeviceResource($device))->response()->setStatusCode(201);
    }

    public function show(BiometricDevice $biometricDevice)
    {
        return new BiometricDeviceResource($biometricDevice);
    }

    public function update(BiometricDeviceRequest $request, BiometricDevice $biometricDevice)
    {
        $biometricDevice->update($request->validated());

        return new BiometricDeviceResource($biometricDevice);
    }

    public function destroy(BiometricDevice $biometricDevice)
    {
        $biometricDevice->delete();

        return response()->json(['message' => 'Biometric device deleted.']);
    }
}
