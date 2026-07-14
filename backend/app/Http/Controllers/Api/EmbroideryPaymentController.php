<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmbroideryPaymentRequest;
use App\Http\Resources\EmbroideryPaymentResource;
use App\Models\EmbroideryPayment;
use App\Models\EmbroideryVendor;
use App\Services\EmbroideryWorkService;
use Illuminate\Http\Request;

class EmbroideryPaymentController extends Controller
{
    public function __construct(private EmbroideryWorkService $service)
    {
    }

    public function index(Request $request)
    {
        $query = EmbroideryPayment::query()->with('vendor:id,name,code');

        if ($request->filled('embroidery_vendor_id')) {
            $query->where('embroidery_vendor_id', $request->query('embroidery_vendor_id'));
        }
        if ($request->filled('mode')) {
            $query->where('mode', $request->query('mode'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('payment_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('payment_date', '<=', $request->query('date_to'));
        }

        $query->orderByDesc('id');

        $perPage = min((int) $request->query('per_page', 15), 100);

        return EmbroideryPaymentResource::collection($query->paginate($perPage));
    }

    public function store(EmbroideryPaymentRequest $request)
    {
        $data = $request->validated();
        $data['created_by'] = $request->user()?->id;

        $vendor = EmbroideryVendor::findOrFail($data['embroidery_vendor_id']);
        $payment = $this->service->pay($vendor, $data);

        return (new EmbroideryPaymentResource($payment->load('vendor')))
            ->response()
            ->setStatusCode(201);
    }
}
