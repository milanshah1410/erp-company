<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendCampaignEmail;
use App\Models\Customer;
use App\Models\MarketingCampaign;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Marketing Campaign email blasts (SRS §19). Compose a subject + HTML body,
 * target an audience (all customers with an email / a customer category / a
 * manual address list), and dispatch to every recipient via the tenant-aware
 * SendCampaignEmail queue job, which records per-recipient delivery.
 */
class MarketingCampaignController extends Controller
{
    public function index(Request $request)
    {
        $query = MarketingCampaign::query()->with(['customerCategory:id,name', 'creator:id,name']);

        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('subject', 'like', "%{$search}%"));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $sort = $request->query('sort');
        if ($sort && in_array($sort, ['name', 'subject', 'status', 'recipient_count', 'sent_at', 'created_at'], true)) {
            $query->orderBy($sort, $request->query('direction') === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderByDesc('id');
        }
        $perPage = min((int) $request->query('per_page', 15), 100);

        $page = $query->paginate($perPage);
        $page->getCollection()->transform(fn (MarketingCampaign $c) => $this->payload($c));

        return $page;
    }

    public function show(MarketingCampaign $marketingCampaign)
    {
        $marketingCampaign->load(['customerCategory:id,name', 'creator:id,name']);

        return response()->json(['data' => $this->payload($marketingCampaign, full: true)]);
    }

    public function store(Request $request)
    {
        $data = $this->validateCampaign($request);

        $campaign = MarketingCampaign::create($data + [
            'status' => 'draft',
            'created_by' => $request->user()?->id,
        ]);

        return response()->json(['data' => $this->payload($campaign->fresh(['customerCategory:id,name']), full: true)], 201);
    }

    public function update(Request $request, MarketingCampaign $marketingCampaign)
    {
        if (in_array($marketingCampaign->status, ['sending', 'sent'], true)) {
            return response()->json(['message' => 'A sent campaign can no longer be edited.'], 422);
        }

        $marketingCampaign->update($this->validateCampaign($request));

        return response()->json(['data' => $this->payload($marketingCampaign->fresh(['customerCategory:id,name']), full: true)]);
    }

    public function destroy(MarketingCampaign $marketingCampaign)
    {
        if ($marketingCampaign->status === 'sending') {
            return response()->json(['message' => 'A campaign that is currently sending cannot be deleted.'], 422);
        }
        $marketingCampaign->delete();

        return response()->json(['message' => 'Campaign deleted.']);
    }

    /** Resolve the recipient count for a prospective/saved audience (compose UI). */
    public function preview(Request $request)
    {
        $data = $request->validate([
            'audience' => ['required', Rule::in(['all', 'category', 'manual'])],
            'customer_category_id' => ['nullable', 'integer', 'exists:customer_categories,id'],
            'manual_recipients' => ['nullable', 'array'],
            'manual_recipients.*.email' => ['required_with:manual_recipients', 'email'],
        ]);

        $transient = new MarketingCampaign([
            'audience' => $data['audience'],
            'customer_category_id' => $data['customer_category_id'] ?? null,
            'manual_recipients' => $data['manual_recipients'] ?? [],
        ]);

        $recipients = $transient->resolveRecipients();

        return response()->json([
            'data' => [
                'count' => count($recipients),
                'sample' => array_slice($recipients, 0, 5),
            ],
        ]);
    }

    /** Dispatch the campaign to its audience. */
    public function send(MarketingCampaign $marketingCampaign)
    {
        if ($marketingCampaign->status === 'sending') {
            return response()->json(['message' => 'This campaign is already sending.'], 422);
        }

        if (count($marketingCampaign->resolveRecipients()) === 0) {
            return response()->json(['message' => 'This campaign has no valid recipients.'], 422);
        }

        $marketingCampaign->update(['status' => 'sending']);

        SendCampaignEmail::dispatch(
            tenantId: app(TenantContext::class)->id(),
            campaignId: $marketingCampaign->id,
        );

        return response()->json([
            'message' => 'Campaign is sending. Delivery progress will update shortly.',
            'data' => $this->payload($marketingCampaign->fresh(['customerCategory:id,name']), full: true),
        ], 202);
    }

    private function validateCampaign(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string'],
            'audience' => ['required', Rule::in(['all', 'category', 'manual'])],
            'customer_category_id' => ['nullable', 'required_if:audience,category', 'integer', 'exists:customer_categories,id'],
            'manual_recipients' => ['nullable', 'required_if:audience,manual', 'array', 'min:1'],
            'manual_recipients.*.email' => ['required', 'email'],
            'manual_recipients.*.name' => ['nullable', 'string', 'max:150'],
        ]);
    }

    private function payload(MarketingCampaign $c, bool $full = false): array
    {
        $data = [
            'id' => $c->id,
            'name' => $c->name,
            'subject' => $c->subject,
            'audience' => $c->audience,
            'customer_category_id' => $c->customer_category_id,
            'customer_category' => $c->customerCategory?->name,
            'status' => $c->status,
            'recipient_count' => $c->recipient_count,
            'sent_count' => $c->sent_count,
            'failed_count' => $c->failed_count,
            'sent_at' => $c->sent_at,
            'by' => $c->creator?->name,
            'created_at' => $c->created_at,
        ];

        if ($full) {
            $data['body'] = $c->body;
            $data['manual_recipients'] = $c->manual_recipients ?? [];
        }

        return $data;
    }
}
