<?php

declare(strict_types=1);

namespace App\Modules\Industry\Toys\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Modules\Industry\Toys\Services\SafetyComplianceService;
use Illuminate\Http\Request;

/**
 * The counter's safety lookup.
 *
 * Two endpoints, both read-only, both answering the question in front of a
 * customer rather than in a report: is this suitable for the child it is being
 * bought for, and may we sell it at all?
 *
 * `inspect` takes the whole cart, in the same shape and at the same moment as
 * the pharmacy module's prescription check — while the customer is still
 * choosing, so a refusal happens before the queue rather than at the card
 * machine. The till calls it as lines are added.
 */
class ComplianceController extends Controller
{
    public function __construct(private readonly SafetyComplianceService $safety) {}

    /** Age warnings and certificate state for a whole cart. */
    public function inspect(Request $request)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0'],
        ]);

        return response()->json($this->safety->inspect($data['items']));
    }

    /**
     * One article, in full.
     *
     * What the shelf-edge label prints and what a counter reads out when a
     * grandparent asks whether a chemistry set is all right for a nine-year-old.
     */
    public function product(Product $product)
    {
        $product->load(['toyAgeGrade', 'toySafetyCategory']);

        return response()->json(['data' => $this->safety->assess($product)]);
    }
}
