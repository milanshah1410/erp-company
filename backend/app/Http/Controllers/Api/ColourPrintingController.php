<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ColourPrintingRequest;
use App\Http\Resources\ColourPrintingResource;
use App\Models\ColourPrinting;
use Illuminate\Http\Request;

class ColourPrintingController extends Controller
{
    public function index(Request $request)
    {
        $query = ColourPrinting::query()->with('printerVendor:id,name');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('print_type', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%")
                    ->orWhereHas('printerVendor', fn ($v) => $v->where('name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('printer_vendor_id')) {
            $query->where('printer_vendor_id', $request->query('printer_vendor_id'));
        }

        $sort = $request->query('sort', 'created_at');
        $direction = $request->query('direction', 'desc');
        if (in_array($sort, ['print_type', 'cost', 'status', 'created_at'], true)) {
            $query->orderBy($sort, $direction === 'asc' ? 'asc' : 'desc');
        }

        $perPage = min((int) $request->query('per_page', 15), 100);

        return ColourPrintingResource::collection($query->paginate($perPage));
    }

    public function store(ColourPrintingRequest $request)
    {
        $printing = ColourPrinting::create($request->validated())->refresh();

        return (new ColourPrintingResource($printing->load('printerVendor:id,name')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(ColourPrinting $colourPrinting)
    {
        return new ColourPrintingResource($colourPrinting->load('printerVendor:id,name'));
    }

    public function update(ColourPrintingRequest $request, ColourPrinting $colourPrinting)
    {
        $colourPrinting->update($request->validated());

        return new ColourPrintingResource($colourPrinting->load('printerVendor:id,name'));
    }

    public function destroy(ColourPrinting $colourPrinting)
    {
        $colourPrinting->delete();

        return response()->json(['message' => 'Colour printing record deleted.']);
    }
}
