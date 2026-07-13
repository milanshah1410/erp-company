<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GstRegistrationRequest;
use App\Http\Resources\GstRegistrationResource;
use App\Models\GstRegistration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GstRegistrationController extends Controller
{
    public function index(Request $request)
    {
        $query = GstRegistration::query()->with(['company:id,name', 'branch:id,name']);

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->query('company_id'));
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->query('branch_id'));
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('gstin', 'like', "%{$search}%")
                    ->orWhere('legal_name', 'like', "%{$search}%")
                    ->orWhere('trade_name', 'like', "%{$search}%");
            });
        }

        $query->orderByDesc('is_primary')->orderBy('state_code');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return GstRegistrationResource::collection($query->paginate($perPage));
    }

    public function store(GstRegistrationRequest $request)
    {
        $data = $request->validated();

        $registration = DB::transaction(function () use ($data) {
            $registration = GstRegistration::create($data)->refresh();
            if ($registration->is_primary) {
                $this->demoteOtherPrimaries($registration);
            }

            return $registration;
        });

        return (new GstRegistrationResource($registration->load(['company:id,name', 'branch:id,name'])))
            ->response()->setStatusCode(201);
    }

    public function show(GstRegistration $gstRegistration)
    {
        return new GstRegistrationResource($gstRegistration->load(['company:id,name', 'branch:id,name']));
    }

    public function update(GstRegistrationRequest $request, GstRegistration $gstRegistration)
    {
        $data = $request->validated();

        DB::transaction(function () use ($gstRegistration, $data) {
            $gstRegistration->update($data);
            if ($gstRegistration->is_primary) {
                $this->demoteOtherPrimaries($gstRegistration);
            }
        });

        return new GstRegistrationResource($gstRegistration->load(['company:id,name', 'branch:id,name']));
    }

    public function destroy(GstRegistration $gstRegistration)
    {
        $gstRegistration->delete();

        return response()->json(['message' => 'GST registration deleted.']);
    }

    /**
     * Only one registration per company is the primary/default on documents.
     */
    private function demoteOtherPrimaries(GstRegistration $registration): void
    {
        GstRegistration::query()
            ->where('company_id', $registration->company_id)
            ->whereKeyNot($registration->id)
            ->where('is_primary', true)
            ->update(['is_primary' => false]);
    }
}
