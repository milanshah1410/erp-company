<?php

declare(strict_types=1);

namespace App\Modules\Industry\Cosmetics\Services;

use App\Models\Batch;
use App\Models\Product;
use App\Modules\Industry\Cosmetics\Models\OpenUnit;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Breaking the seal, and what it costs.
 *
 * Opening a unit is a stock movement, and that is the whole reason this is a
 * service rather than a form that writes a row. The tester on the counter is not
 * "stock we still have" — it will never be billed, it cannot be transferred, and
 * leaving it in the on-hand figure means the shop reorders late and the stock
 * count never balances. So the unit goes out of stock the moment it is opened.
 *
 * It leaves as `sample`, which already exists in the stock-ledger movement types
 * and means exactly this: goods that left without a sale. Not `damage` — nothing
 * is damaged, and a tester posted as damage disappears into the same bucket as
 * breakage, which is the number a shop is actually trying to keep separate. The
 * distinction survives on the row itself in `purpose`, so "what did testers cost
 * us this quarter" and "what did we break" stay two questions.
 *
 * Closing a unit posts nothing. The stock left months ago; whether the tester was
 * used up or thrown away past its PAO changes what the counter manager is judged
 * on, not what is on the shelf. Posting anything at close would double-count it.
 */
class OpenUnitService
{
    public function __construct(private readonly StockService $stock) {}

    /**
     * Take a unit out of saleable stock and start its PAO clock.
     *
     * @param  array<string, mixed>  $data
     */
    public function open(array $data, ?int $userId = null): OpenUnit
    {
        $product = Product::findOrFail((int) $data['product_id']);
        $quantity = (float) ($data['quantity'] ?? 1);

        if ($quantity <= 0) {
            throw new RuntimeException('A unit being opened has to be a positive quantity.');
        }

        $openedOn = (string) ($data['opened_on'] ?? now()->toDateString());

        /**
         * The PAO is copied off the product unless the counter overrode it, and
         * the expiry is computed once, here. Both are snapshots — see the note in
         * OpenUnit — so a later correction to the product master cannot move the
         * death date of a tester already in use.
         */
        $pao = isset($data['pao_months']) && $data['pao_months'] !== null && $data['pao_months'] !== ''
            ? (int) $data['pao_months']
            : ($product->pao_months !== null ? (int) $product->pao_months : null);

        return DB::transaction(function () use ($data, $product, $quantity, $openedOn, $pao, $userId) {
            $batch = isset($data['batch_id']) && $data['batch_id']
                ? Batch::find((int) $data['batch_id'])
                : null;

            $unit = OpenUnit::create([
                'product_id' => $product->id,
                'variant_id' => $data['variant_id'] ?? null,
                'batch_id' => $batch?->id,
                'warehouse_id' => (int) $data['warehouse_id'],
                'purpose' => $data['purpose'] ?? 'tester',
                'quantity' => $quantity,
                'opened_on' => $openedOn,
                'pao_months' => $pao,
                'expires_on' => $data['expires_on'] ?? OpenUnit::expiryFrom($openedOn, $pao),
                'status' => 'open',
                'opened_by' => $userId,
                'notes' => $data['notes'] ?? null,
            ]);

            /**
             * The batch is drawn down alongside the ledger, the same pairing every
             * outward path in the ERP keeps: StockService moves the warehouse
             * total, the batch row moves its own. Clamped so the batch cannot go
             * negative, because the warehouse ledger below is the authority on
             * whether there was anything to open — it refuses the movement
             * outright if there was not — and a batch row disagreeing with it by
             * a fraction is not worth a second refusal in a different voice.
             */
            if ($batch) {
                $batch->decrement('quantity', min($quantity, (float) $batch->quantity));
            }

            $this->stock->outward(
                warehouseId: (int) $data['warehouse_id'],
                productId: $product->id,
                variantId: $data['variant_id'] ?? null,
                quantity: $quantity,
                type: 'sample',
                referenceType: OpenUnit::class,
                referenceId: $unit->id,
                referenceNumber: 'TESTER-'.$unit->id,
                userId: $userId,
            );

            return $unit;
        });
    }

    /**
     * End a unit's life on the counter.
     *
     * `finished` and `discarded` are both endings and they are not the same one:
     * finished is a tester used up as intended, discarded is one thrown away with
     * product still in it because the clock ran out. Keeping them apart is the
     * only way a shop can tell "our testers work" from "we buy testers nobody
     * touches and bin them twice a year".
     */
    public function close(OpenUnit $unit, string $status, ?string $closedOn = null, ?string $notes = null): OpenUnit
    {
        if (! in_array($status, ['finished', 'discarded'], true)) {
            throw new RuntimeException('A unit can only be closed as finished or discarded.');
        }

        if ($unit->status !== 'open') {
            throw new RuntimeException('This unit has already been closed.');
        }

        $unit->update([
            'status' => $status,
            'closed_on' => $closedOn ?: now()->toDateString(),
            'notes' => $notes !== null && $notes !== '' ? $notes : $unit->notes,
        ]);

        return $unit;
    }

    /**
     * The register, filtered the way the counter asks for it.
     *
     * @param  array<string, mixed>  $filters
     */
    public function register(array $filters = [])
    {
        return OpenUnit::query()
            ->with(['product:id,name,sku', 'variant:id,name,sku', 'warehouse:id,name', 'openedBy:id,name'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['purpose'] ?? null, fn ($q, $purpose) => $q->where('purpose', $purpose))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $wh) => $q->where('warehouse_id', $wh))
            ->when($filters['product_id'] ?? null, fn ($q, $product) => $q->where('product_id', $product))
            // Open units first, then soonest due: the register is opened to find
            // what should have been thrown away last week, not to read history.
            ->orderByRaw("status = 'open' desc")
            ->orderByRaw('expires_on IS NULL, expires_on ASC')
            ->orderByDesc('id');
    }
}
