<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AccountingBookService;
use Illuminate\Http\Request;

/**
 * Read-only account books. Each endpoint returns { data: [...], summary: {...} },
 * the same envelope the frontend ledger views already consume.
 */
class AccountBookController extends Controller
{
    public function __construct(private readonly AccountingBookService $books) {}

    public function cashBook(Request $request)
    {
        return response()->json($this->books->cashBook(
            $request->query('from'),
            $request->query('to'),
        ));
    }

    public function bankBook(Request $request)
    {
        return response()->json($this->books->bankBook(
            $request->query('from'),
            $request->query('to'),
            $request->filled('bank_account_id') ? (int) $request->query('bank_account_id') : null,
        ));
    }

    public function dayBook(Request $request)
    {
        return response()->json($this->books->dayBook(
            $request->query('from'),
            $request->query('to'),
        ));
    }

    public function journal(Request $request)
    {
        return response()->json($this->books->journal(
            $request->query('from'),
            $request->query('to'),
        ));
    }

    public function pettyCash(Request $request)
    {
        return response()->json($this->books->pettyCashBook(
            $request->query('from'),
            $request->query('to'),
        ));
    }
}
