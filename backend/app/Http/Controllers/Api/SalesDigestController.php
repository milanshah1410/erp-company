<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Notifications\DailySalesDigestNotification;
use App\Services\SalesDigestService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * The day's trading summary, on demand (retail base §8c).
 *
 * The scheduled evening push is the point of the digest; these two endpoints
 * exist because a figure set nobody can look at before 21:30 is a figure set
 * nobody trusts. `show` renders it on screen, `send` puts it in the caller's own
 * bell and inbox — which is also how an owner checks the digest is configured
 * before relying on it for a month.
 */
class SalesDigestController extends Controller
{
    public function __construct(private readonly SalesDigestService $digests) {}

    /** Today's figures (or another day's), as the digest would report them. */
    public function show(Request $request)
    {
        $data = $request->validate(['date' => ['nullable', 'date']]);
        $date = $data['date'] ?? now()->toDateString();

        $digest = $this->digests->forDate($date);

        return response()->json([
            'data' => $digest,
            'summary_line' => $this->digests->oneLine($digest),
            'worth_sending' => $this->digests->isWorthSending($digest),
        ]);
    }

    /**
     * Send it to the caller, now.
     *
     * Only ever to the caller — not to the workspace's owners. Somebody testing
     * their configuration must not be able to put an unexpected evening summary
     * in four other people's inboxes, and the scheduled command is the thing that
     * legitimately mails everybody.
     */
    public function send(Request $request)
    {
        $data = $request->validate(['date' => ['nullable', 'date']]);
        $date = $data['date'] ?? now()->toDateString();

        $digest = $this->digests->forDate($date);
        $tenant = Tenant::find(app(TenantContext::class)->id());

        $request->user()->notify(new DailySalesDigestNotification($digest, (string) $tenant?->company_name));

        return response()->json([
            'message' => "Sent you the summary for {$date}. It also goes to your notification bell.",
            'summary_line' => $this->digests->oneLine($digest),
        ]);
    }
}
