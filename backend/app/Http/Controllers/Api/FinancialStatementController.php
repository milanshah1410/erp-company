<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ExportsReports;
use App\Http\Controllers\Controller;
use App\Services\FinancialStatementService;
use Illuminate\Http\Request;

/**
 * Derived financial statements — Profit & Loss, Balance Sheet, Trial Balance.
 * Read-only; each returns the standard { data, meta } envelope and supports
 * ?format=csv|xlsx|pdf via {@see ExportsReports}. See
 * {@see FinancialStatementService} for how the figures are derived.
 */
class FinancialStatementController extends Controller
{
    use ExportsReports;

    public function __construct(private readonly FinancialStatementService $statements) {}

    public function profitAndLoss(Request $request)
    {
        $result = $this->statements->profitAndLoss(
            $request->query('date_from'),
            $request->query('date_to'),
        );

        return $this->exportResponse($request, $result['data'], [
            'section' => 'Section', 'particulars' => 'Particulars', 'amount' => 'Amount',
        ], 'profit-and-loss', $result['meta']);
    }

    public function balanceSheet(Request $request)
    {
        $result = $this->statements->balanceSheet($request->query('date_to'));

        return $this->exportResponse($request, $result['data'], [
            'side' => 'Side', 'group' => 'Group', 'particulars' => 'Particulars', 'amount' => 'Amount',
        ], 'balance-sheet', $result['meta']);
    }

    public function trialBalance(Request $request)
    {
        $result = $this->statements->trialBalance(
            $request->query('date_from'),
            $request->query('date_to'),
        );

        return $this->exportResponse($request, $result['data'], [
            'account' => 'Account', 'debit' => 'Debit', 'credit' => 'Credit',
        ], 'trial-balance', $result['meta']);
    }
}
