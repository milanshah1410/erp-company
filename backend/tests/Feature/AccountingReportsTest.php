<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\GstReportController;
use App\Http\Controllers\Api\TaxRegisterController;
use App\Models\BankAccount;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Services\FinancialStatementService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Exercises the hand-written aggregate SQL in FinancialStatementService and
 * asserts the derived statements foot (trial balance debit == credit; balance
 * sheet assets == liabilities + equity).
 */
class AccountingReportsTest extends TestCase
{
    use RefreshDatabase;

    private FinancialStatementService $statements;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['company_name' => 'Test Co', 'status' => 'active']);
        app(TenantContext::class)->set($tenant->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        // Seed a little balance-sheet substance.
        BankAccount::forceCreate(['name' => 'Cash in Hand', 'type' => 'cash', 'is_petty_cash' => true, 'opening_balance' => 5000]);
        BankAccount::forceCreate(['name' => 'HDFC Current', 'type' => 'bank', 'opening_balance' => 20000]);
        Customer::forceCreate(['code' => 'C1', 'name' => 'Debtor A', 'opening_balance' => 3000]);
        Supplier::forceCreate(['code' => 'S1', 'name' => 'Creditor A', 'opening_balance' => 1500]);

        $this->statements = app(FinancialStatementService::class);
    }

    public function test_profit_and_loss_runs_and_returns_meta(): void
    {
        $pl = $this->statements->profitAndLoss(null, now()->toDateString());

        $this->assertArrayHasKey('net_profit', $pl['meta']);
        $this->assertIsArray($pl['data']);
    }

    public function test_trial_balance_foots(): void
    {
        $tb = $this->statements->trialBalance(null, now()->toDateString());

        $this->assertEqualsWithDelta($tb['meta']['total_debit'], $tb['meta']['total_credit'], 0.01);
    }

    public function test_balance_sheet_balances(): void
    {
        $bs = $this->statements->balanceSheet(now()->toDateString());

        $this->assertEqualsWithDelta($bs['meta']['total_assets'], $bs['meta']['total_liabilities_equity'], 0.01);
    }

    public function test_gst_and_tax_register_endpoints_execute(): void
    {
        $gst = app(GstReportController::class);
        $this->assertEquals(200, $gst->summary(new Request)->getStatusCode());
        $this->assertEquals(200, $gst->gstr1(new Request)->getStatusCode());

        $tax = app(TaxRegisterController::class);
        $this->assertEquals(200, $tax->tds(new Request)->getStatusCode());
        $this->assertEquals(200, $tax->tcs(new Request)->getStatusCode());
    }
}
