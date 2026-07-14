<?php

namespace Tests\Feature;

use App\Models\PieceRate;
use App\Models\Product;
use App\Models\RateCard;
use App\Models\StockLedger;
use App\Models\Tailor;
use App\Models\TailorWork;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Services\TailorWorkService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TailorWorkTest extends TestCase
{
    use RefreshDatabase;

    private TailorWorkService $service;

    private Tenant $tenant;

    private Tailor $tailor;

    private Product $product;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['company_name' => 'Test Co', 'status' => 'active']);
        app(TenantContext::class)->set($this->tenant->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        $this->service = app(TailorWorkService::class);
        $this->tailor = Tailor::create(['code' => 'T1', 'name' => 'Ramesh', 'opening_balance' => 0, 'is_active' => true]);
        $this->product = Product::forceCreate(['sku' => 'SHIRT', 'name' => 'Party Shirt']);
        $this->warehouse = Warehouse::forceCreate(['code' => 'WH1', 'name' => 'Main', 'type' => 'main']);
    }

    private function rateCardWithOperations(array $rates): RateCard
    {
        $card = RateCard::create(['product_id' => $this->product->id, 'standard_rate' => 0, 'is_active' => true]);
        foreach ($rates as $i => $rate) {
            PieceRate::create(['rate_card_id' => $card->id, 'operation' => "op{$i}", 'rate' => $rate, 'sequence' => $i]);
        }

        return $card->fresh();
    }

    private function makeWork(float $rate, float $qty = 100): TailorWork
    {
        return TailorWork::create([
            'work_number' => TailorWork::nextNumber(),
            'tailor_id' => $this->tailor->id,
            'product_id' => $this->product->id,
            'issued_qty' => $qty,
            'rate' => $rate,
            'status' => 'pending',
            'issue_date' => now()->toDateString(),
        ]);
    }

    public function test_effective_rate_sums_operation_piece_rates(): void
    {
        $card = $this->rateCardWithOperations([20, 80, 20]); // cutting + stitching + finishing

        $this->assertSame(120.0, $card->effectiveRate());
    }

    public function test_effective_rate_falls_back_to_standard_when_no_operations(): void
    {
        $card = RateCard::create(['product_id' => $this->product->id, 'standard_rate' => 95, 'is_active' => true]);

        $this->assertSame(95.0, $card->effectiveRate());
    }

    public function test_partial_then_full_completion_accrues_earnings_and_updates_status(): void
    {
        $work = $this->makeWork(rate: 120, qty: 100);

        // Complete 40 of 100.
        $this->service->complete($work, ['qty' => 40]);
        $work->refresh();
        $this->assertSame('partial', $work->status);
        $this->assertSame(40.0, (float) $work->completed_qty);
        $this->assertSame(60.0, $work->remainingQty());
        $this->assertSame(4800.0, $this->tailor->fresh()->totalEarned()); // 40 * 120

        // Complete the remaining 60.
        $this->service->complete($work, ['qty' => 60]);
        $work->refresh();
        $this->assertSame('completed', $work->status);
        $this->assertSame(12000.0, $this->tailor->fresh()->totalEarned()); // 100 * 120
    }

    public function test_payment_reduces_outstanding_balance(): void
    {
        $work = $this->makeWork(rate: 100, qty: 50);
        $this->service->complete($work, ['qty' => 50]); // earns 5000

        $this->assertSame(5000.0, $this->tailor->fresh()->outstandingBalance());

        $this->service->pay($this->tailor, ['amount' => 2000, 'mode' => 'cash']);

        $tailor = $this->tailor->fresh();
        $this->assertSame(2000.0, $tailor->totalPaid());
        $this->assertSame(3000.0, $tailor->outstandingBalance());
    }

    public function test_completion_can_receive_finished_goods_into_stock(): void
    {
        $work = $this->makeWork(rate: 100, qty: 30);

        $this->service->complete($work, [
            'qty' => 30,
            'received_to_stock' => true,
            'warehouse_id' => $this->warehouse->id,
        ]);

        $ledger = StockLedger::where('type', 'production')
            ->where('product_id', $this->product->id)
            ->first();

        $this->assertNotNull($ledger);
        $this->assertSame(30.0, (float) $ledger->quantity_in);
    }

    public function test_opening_balance_is_included_in_outstanding(): void
    {
        $this->tailor->update(['opening_balance' => 1500]);
        $work = $this->makeWork(rate: 100, qty: 10);
        $this->service->complete($work, ['qty' => 10]); // earns 1000

        // opening 1500 + earned 1000 - paid 0 = 2500
        $this->assertSame(2500.0, $this->tailor->fresh()->outstandingBalance());
    }
}
