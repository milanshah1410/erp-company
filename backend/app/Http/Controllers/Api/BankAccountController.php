<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BankAccountRequest;
use App\Http\Resources\BankAccountResource;
use App\Models\BankAccount;
use Illuminate\Http\Request;

class BankAccountController extends Controller
{
    public function index(Request $request)
    {
        $query = BankAccount::query()->with('branch:id,name');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('bank_name', 'like', "%{$search}%")
                    ->orWhere('account_number', 'like', "%{$search}%");
            });
        }
        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $query->orderBy('name');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return BankAccountResource::collection($query->paginate($perPage));
    }

    public function store(BankAccountRequest $request)
    {
        $account = BankAccount::create($request->validated());

        return (new BankAccountResource($account->load('branch')))
            ->response()->setStatusCode(201);
    }

    public function show(BankAccount $bankAccount)
    {
        return new BankAccountResource($bankAccount->load('branch'));
    }

    public function update(BankAccountRequest $request, BankAccount $bankAccount)
    {
        $bankAccount->update($request->validated());

        return new BankAccountResource($bankAccount->load('branch'));
    }

    public function destroy(BankAccount $bankAccount)
    {
        if ($bankAccount->vouchers()->exists()) {
            return response()->json(['message' => 'Cannot delete an account that has vouchers booked against it. Deactivate it instead.'], 422);
        }
        $bankAccount->delete();

        return response()->json(['message' => 'Bank account deleted.']);
    }
}
