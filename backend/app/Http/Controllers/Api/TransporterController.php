<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TransporterRequest;
use App\Http\Resources\TransporterResource;
use App\Models\Transporter;
use Illuminate\Http\Request;

/**
 * Transporter master — the inland haulage contractor moving cargo between port
 * / CFS / ICD and the tenant's factory or warehouse.
 */
class TransporterController extends Controller
{
    public function index(Request $request)
    {
        $query = Transporter::query();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('gst_number', 'like', "%{$search}%");
            });
        }
        if ($request->filled('transport_mode')) {
            $query->where('transport_mode', $request->query('transport_mode'));
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $sort = $request->query('sort', 'name');
        $direction = $request->query('direction', 'asc') === 'desc' ? 'desc' : 'asc';
        $query->orderBy(in_array($sort, ['name', 'code', 'city', 'created_at'], true) ? $sort : 'name', $direction);

        $perPage = min((int) $request->query('per_page', 15), 100);

        return TransporterResource::collection($query->paginate($perPage));
    }

    public function store(TransporterRequest $request)
    {
        $transporter = Transporter::create($request->validated());

        return (new TransporterResource($transporter))->response()->setStatusCode(201);
    }

    public function show(Transporter $transporter)
    {
        return new TransporterResource($transporter);
    }

    public function update(TransporterRequest $request, Transporter $transporter)
    {
        $transporter->update($request->validated());

        return new TransporterResource($transporter);
    }

    public function destroy(Transporter $transporter)
    {
        $transporter->delete();

        return response()->json(['message' => 'Transporter deleted.']);
    }
}
