<?php

namespace Tests\Feature;

use App\Models\Contractor;
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

    public function test_reworkable_rejects_are_unpaid_and_stay_pending(): void
    {
        $work = $this->makeWork(rate: 100, qty: 100);

        // Submit 50: 40 accepted, 10 fail QC and go back for rework.
        $this->service->complete($work, ['qty' => 40, 'rejected_qty' => 10, 'reject_action' => 'rework']);
        $work->refresh();

        $this->assertSame('partial', $work->status);
        $this->assertSame(40.0, (float) $work->completed_qty);
        $this->assertSame(10.0, (float) $work->rejected_qty);
        $this->assertSame(0.0, (float) $work->scrapped_qty);
        // Rework does not consume issued: remaining = 100 - 40 accepted - 0 scrapped.
        $this->assertSame(60.0, $work->remainingQty());
        // Only the 40 accepted pieces are paid.
        $this->assertSame(4000.0, $this->tailor->fresh()->totalEarned());
    }

    public function test_scrapped_rejects_close_the_order_and_are_unpaid(): void
    {
        $work = $this->makeWork(rate: 100, qty: 100);

        // 90 accepted, 10 scrapped — every issued piece is now resolved.
        $this->service->complete($work, ['qty' => 90, 'rejected_qty' => 10, 'reject_action' => 'scrap']);
        $work->refresh();

        $this->assertSame('completed', $work->status);
        $this->assertSame(10.0, (float) $work->scrapped_qty);
        $this->assertSame(0.0, $work->remainingQty());
        $this->assertSame(9000.0, $this->tailor->fresh()->totalEarned()); // 90 * 100
    }

    public function test_approved_bonus_credits_the_ledger_but_draft_does_not(): void
    {
        $draft = $this->service->awardBonus($this->tailor, ['basis' => 'manual', 'amount' => 500]);
        $this->assertSame(0.0, $this->tailor->fresh()->totalBonus());
        $this->assertSame(0.0, $this->tailor->fresh()->outstandingBalance());

        $draft->update(['status' => 'approved']);
        $this->assertSame(500.0, $this->tailor->fresh()->totalBonus());
        $this->assertSame(500.0, $this->tailor->fresh()->outstandingBalance());
    }

    public function test_pieces_bonus_is_computed_above_target(): void
    {
        $work = $this->makeWork(rate: 100, qty: 100);
        $this->service->complete($work, ['qty' => 80]); // 80 accepted pieces

        // basis=pieces: (80 - 50 target) * 5 = 150
        $amount = $this->service->computeBonus($this->tailor, ['basis' => 'pieces', 'rate' => 5, 'target' => 50]);
        $this->assertSame(150.0, $amount);
    }

    public function test_contractor_rolls_up_its_tailors_outstanding(): void
    {
        $contractor = Contractor::create(['code' => 'C1', 'name' => 'Acme Tailors', 'opening_balance' => 0, 'is_active' => true]);
        $this->tailor->update(['contractor_id' => $contractor->id]);

        $second = Tailor::create(['code' => 'T2', 'name' => 'Suresh', 'contractor_id' => $contractor->id, 'opening_balance' => 0, 'is_active' => true]);

        $this->service->complete($this->makeWork(rate: 100, qty: 10), ['qty' => 10]); // Ramesh earns 1000
        $this->service->complete(TailorWork::create([
            'work_number' => TailorWork::nextNumber(),
            'tailor_id' => $second->id,
            'product_id' => $this->product->id,
            'issued_qty' => 5,
            'rate' => 200,
            'status' => 'pending',
            'issue_date' => now()->toDateString(),
        ]), ['qty' => 5]); // Suresh earns 1000

        $this->assertSame(2000.0, $contractor->fresh()->load('tailors')->outstandingBalance());
    }
}
