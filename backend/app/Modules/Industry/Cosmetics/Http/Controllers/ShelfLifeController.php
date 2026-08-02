<?php

declare(strict_types=1);

namespace App\Modules\Industry\Cosmetics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Modules\Industry\Cosmetics\Services\ShelfLifeService;
use Illuminate\Http\Request;

/**
 * The shelf-life board, the counter's pre-check, and the policy behind both.
 *
 * All three read through ShelfLifeService, which is where the two clocks — the
 * printed batch expiry and a tester's period after opening — are reasoned about.
 */
class ShelfLifeController extends Controller
{
    public function __construct(private readonly ShelfLifeService $shelfLife) {}

    /** What is dying: expired batches, short-dated batches, overdue testers. */
    public function board(Request $request)
    {
        return response()->json([
            'data' => $this->shelfLife->board(
                $request->integer('within_days') ?: null,
                $request->integer('warehouse_id') ?: null,
            ),
        ]);
    }

    /**
     * Which lines in the current cart are past their date, so the till can say so
     * *before* the customer has queued to pay rather than refusing the sale at
     * the last step.
     *
     * A POST because it carries a cart, not because it writes one — the same
     * shape, and the same moment in a checkout, as the pharmacy's prescription
     * check and the toy shop's compliance check.
     */
    public function check(Request $request)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['nullable', 'numeric'],
            'items.*.variant_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
        ]);

        return response()->json([
            'data' => $this->shelfLife->inspect($data['items'], $data['warehouse_id'] ?? null),
        ]);
    }

    /**
     * Change what the counter does about a date.
     *
     * Three settings and one screen, deliberately: this is a shop-wide policy an
     * owner sets once, not a per-article property. Written the same way
     * Distribution's credit enforcement is — see ShelfLifeService for why it is
     * settings rather than columns.
     */
    public function updatePolicy(Request $request)
    {
        $data = $request->validate([
            'block_expired' => ['required', 'boolean'],
            'min_shelf_life_days' => ['required', 'integer', 'min:0', 'max:1095'],
            'near_expiry_days' => ['required', 'integer', 'min:1', 'max:1095'],
        ]);

        Setting::set(ShelfLifeService::KEY_BLOCK_EXPIRED, $data['block_expired'] ? '1' : '0');
        Setting::set(ShelfLifeService::KEY_MIN_SHELF_LIFE, (string) $data['min_shelf_life_days']);
        Setting::set(ShelfLifeService::KEY_NEAR_EXPIRY, (string) $data['near_expiry_days']);

        return response()->json([
            'message' => 'Shelf-life policy saved.',
            'data' => $this->shelfLife->policy(),
        ]);
    }
}
