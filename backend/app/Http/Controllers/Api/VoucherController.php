<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\VoucherRequest;
use App\Http\Resources\VoucherResource;
use App\Models\Voucher;
use Illuminate\Http\Request;

class VoucherController extends Controller
{
    public function index(Request $request)
    {
        $query = Voucher::query()->with(['bankAccount:id,name', 'counterAccount:id,name', 'branch:id,name', 'creator:id,name']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('voucher_number', 'like', "%{$search}%")
                    ->orWhere('party_name', 'like', "%{$search}%")
                    ->orWhere('reference_no', 'like', "%{$search}%")
                    ->orWhere('narration', 'like', "%{$search}%");
            });
        }
        if ($request->filled('voucher_type')) {
            $query->where('voucher_type', $request->query('voucher_type'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('bank_account_id')) {
            $query->where(function ($q) use ($request) {
                $id = $request->query('bank_account_id');
                $q->where('bank_account_id', $id)->orWhere('counter_account_id', $id);
            });
        }
        if ($request->filled('date_from')) {
            $query->whereDate('voucher_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('voucher_date', '<=', $request->query('date_to'));
        }

        $query->orderByDesc('voucher_date')->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return VoucherResource::collection($query->paginate($perPage));
    }

    public function store(VoucherRequest $request)
    {
        $data = $request->validated();

        $voucher = Voucher::create([
            ...$data,
            'voucher_number' => Voucher::nextNumber($data['voucher_type']),
            'status' => 'posted',
            'created_by' => $request->user()?->id,
        ]);

        return (new VoucherResource($voucher->load($this->relations())))
            ->response()->setStatusCode(201);
    }

    public function show(Voucher $voucher)
    {
        return new VoucherResource($voucher->load($this->relations()));
    }

    public function update(VoucherRequest $request, Voucher $voucher)
    {
        if ($voucher->status === 'cancelled') {
            return response()->json(['message' => 'A cancelled voucher cannot be edited.'], 422);
        }
        $voucher->update($request->validated());

        return new VoucherResource($voucher->load($this->relations()));
    }

    public function destroy(Voucher $voucher)
    {
        $voucher->delete();

        return response()->json(['message' => 'Voucher deleted.']);
    }

    /** Soft-cancel: excludes the voucher from every book while keeping the audit trail. */
    public function cancel(Voucher $voucher)
    {
        if ($voucher->status === 'cancelled') {
            return response()->json(['message' => 'Voucher is already cancelled.'], 422);
        }
        $voucher->update(['status' => 'cancelled']);

        return new VoucherResource($voucher->load($this->relations()));
    }

    private function relations(): array
    {
        return ['bankAccount', 'counterAccount', 'branch', 'creator'];
    }
}
