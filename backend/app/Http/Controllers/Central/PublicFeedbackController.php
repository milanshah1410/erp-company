<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\CustomerFeedback;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * The customer-facing feedback page (retail base §6). Unauthenticated: the
 * person filling it in is a shopper with a link in an SMS, not a user of the
 * workspace.
 *
 * Runs on the central stack with no tenant resolved from a session — the tenant
 * is derived from the token and activated for the duration of each call, exactly
 * as the public pay page does. The token lookup itself must therefore bypass the
 * tenant scope, which is the one query in this file that does.
 *
 * Read carefully, because this is public surface:
 *
 *  - The only row a caller can ever reach is the one whose 48-character token
 *    they already hold. There is no list endpoint and no search.
 *  - `show` returns the shop's name and the bill number, and nothing else about
 *    the customer or the sale. Enough for the page to say what it is asking
 *    about; not enough to be worth harvesting.
 *  - `submit` writes a rating, a comment and tags. It cannot change who the row
 *    belongs to, cannot reach any other row, and refuses once answered — so a
 *    forwarded link cannot be used to overwrite somebody's review.
 */
class PublicFeedbackController extends Controller
{
    public function __construct(private TenantContext $tenantContext) {}

    /** What the page needs to render: which shop is asking, and about what. */
    public function show(string $token)
    {
        $feedback = $this->locate($token);

        return $this->tenantContext->runWith($feedback->tenant_id, function () use ($feedback) {
            $feedback->load('invoice:id,invoice_number,invoice_date', 'serviceJob:id,job_number');
            $tenant = Tenant::find($feedback->tenant_id);

            return response()->json(['data' => [
                'token' => $feedback->token,
                'shop_name' => $tenant?->company_name,
                'customer_name' => $feedback->customer_name,
                'invoice_number' => $feedback->invoice?->invoice_number,
                'invoice_date' => $feedback->invoice?->invoice_date?->toDateString(),
                'job_number' => $feedback->serviceJob?->job_number,
                // Drives whether the page shows a form or a thank-you.
                'answered' => $feedback->submitted_at !== null,
                'rating' => $feedback->rating,
                'comment' => $feedback->comment,
                'tags' => $feedback->tags ?? [],
                // The chips the customer may pick from. Fixed rather than
                // tenant-defined: a shopper answering on a phone should see four
                // familiar words, not whatever taxonomy the shop invented.
                'tag_options' => self::TAG_OPTIONS,
            ]]);
        });
    }

    /** What a customer may pick from. Kept short — a long list is not answered. */
    public const TAG_OPTIONS = ['Staff', 'Price', 'Product quality', 'Waiting time', 'Cleanliness', 'Delivery'];

    public function submit(Request $request, string $token)
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'tags' => ['nullable', 'array', 'max:6'],
            'tags.*' => ['string', 'max:40'],
        ]);

        $feedback = $this->locate($token);

        return $this->tenantContext->runWith($feedback->tenant_id, function () use ($feedback, $data) {
            /**
             * One answer per link. Not an update: the shop may already have read
             * it, replied to it and counted it, and letting a forwarded link
             * rewrite that is how a review becomes worthless.
             */
            if ($feedback->submitted_at !== null) {
                return response()->json(['message' => 'Thank you — this feedback has already been recorded.'], 422);
            }

            $feedback->update([
                'rating' => $data['rating'],
                'comment' => $data['comment'] ?? null,
                'tags' => $data['tags'] ?? null,
                'status' => 'submitted',
                'submitted_at' => now(),
            ]);

            return response()->json(['message' => 'Thank you for your feedback.']);
        });
    }

    /**
     * Resolve a token to its row with the tenant scope bypassed — there is no
     * tenant yet, which is the whole point of the token.
     */
    private function locate(string $token): CustomerFeedback
    {
        return CustomerFeedback::withoutGlobalScopes()
            ->where('token', $token)
            ->firstOrFail();
    }
}
