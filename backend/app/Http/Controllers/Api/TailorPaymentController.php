<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TailorPaymentRequest;
use App\Http\Resources\TailorPaymentResource;
use App\Models\Tailor;
use App\Models\TailorPayment;
use App\Services\TailorWorkService;
use Illuminate\Http\Request;

class TailorPaymentController extends Controller
{
    public function __construct(private TailorWorkService $service)
    {
    }

    public function index(Request $request)
    {
        $query = TailorPayment::query()->with('tailor:id,name,code');

        if ($request->filled('tailor_id')) {
            $query->where('tailor_id', $request->query('tailor_id'));
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

        return TailorPaymentResource::collection($query->paginate($perPage));
    }

    public function store(TailorPaymentRequest $request)
    {
        $data = $request->validated();
        $data['created_by'] = $request->user()?->id;

        $tailor = Tailor::findOrFail($data['tailor_id']);
        $payment = $this->service->pay($tailor, $data);

        return (new TailorPaymentResource($payment->load('tailor')))
            ->response()
            ->setStatusCode(201);
    }
}
