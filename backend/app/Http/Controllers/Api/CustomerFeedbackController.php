<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerFeedback;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\WhatsappMessage;
use App\Services\SmsService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The shop's own feedback book and its CSAT score (retail base §6).
 *
 * Two doors in, one register: `store` writes down what somebody said at the
 * counter, `requestLinks` sends customers a link and waits. Both end up as rows
 * here and both count towards the score — see the note on the migration for why
 * counting only the second would flatter the shop.
 */
class CustomerFeedbackController extends Controller
{
    public function __construct(private readonly SmsService $sms) {}

    public function index(Request $request)
    {
        $query = CustomerFeedback::query()->with([
            'customer:id,name,phone', 'invoice:id,invoice_number', 'serviceJob:id,job_number', 'responder:id,name',
        ]);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%")
                    ->orWhere('comment', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('invoice', fn ($i) => $i->where('invoice_number', 'like', "%{$search}%"));
            });
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('channel')) {
            $query->where('channel', $request->query('channel'));
        }
        if ($request->filled('rating')) {
            $query->where('rating', (int) $request->query('rating'));
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', (int) $request->query('customer_id'));
        }
        if ($request->filled('invoice_id')) {
            $query->where('invoice_id', (int) $request->query('invoice_id'));
        }
        // The complaints worklist: poor scores nobody has picked up yet. This is
        // the filter the screen opens on when there is anything in it.
        if ($request->boolean('needs_attention')) {
            $query->where('status', 'submitted')->where('rating', '<=', CustomerFeedback::DETRACTOR_UPTO);
        }
        if ($request->boolean('with_comment')) {
            $query->whereNotNull('comment')->where('comment', '!=', '');
        }
        [$from, $to] = $this->period($request);
        if ($from) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to) {
            $query->whereDate('created_at', '<=', $to);
        }

        $perPage = min((int) $request->query('per_page', 25), 100);
        $page = (clone $query)->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => $page->getCollection()->map(fn ($f) => $this->payload($f)),
            'summary' => $this->summary($query),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(CustomerFeedback $feedback)
    {
        return response()->json(['data' => $this->payload(
            $feedback->load('customer:id,name,phone', 'invoice:id,invoice_number', 'serviceJob:id,job_number', 'responder:id,name'),
        )]);
    }

    /** Write down what a customer said — the counter's door into the book. */
    public function store(Request $request)
    {
        $data = $this->validated($request, required: true);

        $feedback = CustomerFeedback::create($data + [
            'channel' => $data['channel'] ?? 'counter',
            // Born answered: somebody is standing there telling the shop.
            'status' => 'submitted',
            'submitted_at' => now(),
            'created_by' => $request->user()?->id,
        ]);

        return response()->json(['data' => $this->payload($feedback->load('customer:id,name,phone', 'invoice:id,invoice_number'))], 201);
    }

    /**
     * Correct a row. Rating and comment are editable because a counter mis-keys
     * them and a book nobody can correct is a book nobody trusts; the reply is
     * not touched here (that is `respond`, which stamps who said it).
     */
    public function update(Request $request, CustomerFeedback $feedback)
    {
        $data = $this->validated($request, required: false);

        // Filling in a rating on an outstanding request completes it — the shop
        // took the answer over the phone rather than the customer following the
        // link, and that is still an answer.
        if (($data['rating'] ?? null) !== null && $feedback->submitted_at === null) {
            $data['status'] = 'submitted';
            $data['submitted_at'] = now();
        }

        $feedback->update($data);

        return response()->json(['data' => $this->payload($feedback->fresh()->load('customer:id,name,phone', 'invoice:id,invoice_number', 'responder:id,name'))]);
    }

    /**
     * The shop's reply, and the state change that goes with it.
     *
     * `acknowledged` and `resolved` are separate on purpose: "we have read this"
     * and "we have dealt with it" are different promises to the customer, and a
     * complaints book that cannot tell them apart lets the second one quietly
     * not happen.
     */
    public function respond(Request $request, CustomerFeedback $feedback)
    {
        $data = $request->validate([
            'response' => ['required', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in(['acknowledged', 'resolved'])],
        ]);

        if ($feedback->submitted_at === null) {
            return response()->json(['message' => 'This customer has not answered yet — there is nothing to reply to.'], 422);
        }

        $feedback->update([
            'response' => $data['response'],
            'status' => $data['status'] ?? 'acknowledged',
            'responded_by' => $request->user()?->id,
            'responded_at' => now(),
        ]);

        return response()->json([
            'message' => 'Reply recorded.',
            'data' => $this->payload($feedback->fresh()->load('customer:id,name,phone', 'invoice:id,invoice_number', 'responder:id,name')),
        ]);
    }

    public function destroy(CustomerFeedback $feedback)
    {
        $feedback->delete();

        return response()->json(['message' => 'Feedback removed.']);
    }

    /**
     * Ask a set of customers how it went: mint a pending row per bill, then SMS
     * the link.
     *
     * Sends are best-effort and reported per row rather than transactional. A
     * gateway that drops the fourth of ten messages must not roll back the nine
     * links already handed out — the row survives with `requested_at` set and the
     * shop can resend it, which is exactly what a resend button is for.
     */
    public function requestLinks(Request $request)
    {
        $data = $request->validate([
            'invoice_ids' => ['required', 'array', 'min:1', 'max:200'],
            'invoice_ids.*' => ['integer', 'exists:invoices,id'],
            'channel' => ['nullable', Rule::in(['link', 'sms', 'whatsapp', 'email'])],
            'send_sms' => ['nullable', 'boolean'],
        ]);

        $channel = $data['channel'] ?? 'sms';
        $sendSms = $request->boolean('send_sms', $channel === 'sms');

        $invoices = Invoice::query()
            ->with('customer:id,name,phone')
            ->whereIn('id', $data['invoice_ids'])
            ->get();

        $created = [];
        $skipped = [];
        $sent = 0;

        foreach ($invoices as $invoice) {
            // One outstanding ask per bill. Two links on one invoice would let a
            // customer answer twice and count twice.
            $existing = CustomerFeedback::query()->where('invoice_id', $invoice->id)->first();
            if ($existing) {
                $skipped[] = [
                    'invoice_number' => $invoice->invoice_number,
                    'reason' => $existing->submitted_at ? 'Already answered' : 'Already asked',
                ];

                continue;
            }

            $feedback = CustomerFeedback::create([
                'customer_id' => $invoice->customer_id,
                'customer_name' => $invoice->customer?->name,
                'customer_phone' => $invoice->customer?->phone,
                'invoice_id' => $invoice->id,
                'channel' => $channel,
                'status' => 'pending',
                'token' => CustomerFeedback::newToken(),
                'requested_at' => now(),
                'created_by' => $request->user()?->id,
            ]);
            $created[] = $feedback;

            if ($sendSms && $invoice->customer?->phone) {
                $body = $this->sms->render('sms_tpl_feedback_request', [
                    'name' => $invoice->customer->name,
                    'number' => $invoice->invoice_number,
                    'link' => $this->publicUrl($feedback),
                    'business' => $this->businessName(),
                ]);
                $message = $this->sms->send($invoice->customer->phone, $body, [
                    'customer_id' => $invoice->customer_id,
                    'template' => 'feedback-request',
                    'created_by' => $request->user()?->id,
                ]);
                if ($message->status === 'sent') {
                    $sent++;
                }
            }
        }

        return response()->json([
            'message' => count($created).' feedback request(s) created'.($sendSms ? ", {$sent} SMS sent" : '').'.',
            'data' => array_map(fn ($f) => $this->payload($f), $created),
            'skipped' => $skipped,
        ]);
    }

    /** Resend the link on an outstanding ask. */
    public function resend(Request $request, CustomerFeedback $feedback)
    {
        if ($feedback->submitted_at !== null) {
            return response()->json(['message' => 'This customer has already answered.'], 422);
        }
        $phone = $feedback->customer_phone ?: $feedback->customer?->phone;
        if (! $phone) {
            return response()->json(['message' => 'No phone number on this request.'], 422);
        }
        if (! $feedback->token) {
            // Counter-recorded rows carry no link; give them one so they can be chased.
            $feedback->update(['token' => CustomerFeedback::newToken()]);
        }

        $body = $this->sms->render('sms_tpl_feedback_request', [
            'name' => $feedback->customer_name ?: ($feedback->customer?->name ?: 'Customer'),
            'number' => $feedback->invoice?->invoice_number ?: '-',
            'link' => $this->publicUrl($feedback),
            'business' => $this->businessName(),
        ]);
        $message = $this->sms->send($phone, $body, [
            'customer_id' => $feedback->customer_id,
            'template' => 'feedback-request',
            'created_by' => $request->user()?->id,
        ]);
        $feedback->update(['requested_at' => now()]);

        return response()->json([
            'message' => $message->status === 'sent' ? 'Link sent again.' : 'Could not send: '.($message->error ?: 'gateway error'),
        ]);
    }

    /**
     * The same ask, over WhatsApp.
     *
     * Returns a `wa.me` link for the browser to open rather than sending
     * anything: WhatsApp in this app is click-to-chat, with no Business API
     * account behind it (see WhatsappController), and pretending otherwise would
     * mean a shop believing messages went out that never did.
     *
     * It still writes to the WhatsApp log, so a feedback ask sent this way sits
     * in the same outbound history as every other message — a shop chasing "did
     * we ask this customer?" must not have to look in two places.
     */
    public function whatsapp(Request $request, CustomerFeedback $feedback)
    {
        if ($feedback->submitted_at !== null) {
            return response()->json(['message' => 'This customer has already answered.'], 422);
        }

        $phone = $feedback->customer_phone ?: $feedback->customer?->phone;
        if (! $phone) {
            return response()->json(['message' => 'No phone number on this request.'], 422);
        }
        if (! $feedback->token) {
            $feedback->update(['token' => CustomerFeedback::newToken()]);
        }

        $body = $this->sms->render('sms_tpl_feedback_request', [
            'name' => $feedback->customer_name ?: ($feedback->customer?->name ?: 'Customer'),
            'number' => $feedback->invoice?->invoice_number ?: '-',
            'link' => $this->publicUrl($feedback),
            'business' => $this->businessName(),
        ]);

        // Same normalisation rule as WhatsappController: bare local numbers get
        // the tenant's country code, or wa.me will not resolve them.
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $cc = preg_replace('/\D+/', '', (string) Setting::get('whatsapp_country_code', '91')) ?: '91';
        if (strlen($digits) <= 10) {
            $digits = $cc.$digits;
        }
        $link = 'https://wa.me/'.$digits.'?text='.rawurlencode($body);

        WhatsappMessage::create([
            'customer_id' => $feedback->customer_id,
            'phone' => $digits,
            'template' => 'feedback-request',
            'body' => $body,
            'channel' => 'click_to_chat',
            'status' => 'sent',
            'link' => $link,
            'created_by' => $request->user()?->id,
        ]);

        $feedback->update(['requested_at' => now(), 'channel' => 'whatsapp']);

        return response()->json([
            'message' => 'Opening WhatsApp — the message is ready to send.',
            'data' => ['link' => $link],
        ]);
    }

    /**
     * Bills eligible to be asked about: recent, not already asked, with a
     * customer who has a phone number. Feeds the "ask for feedback" picker so a
     * shop is not choosing from its whole sales history.
     */
    public function askable(Request $request)
    {
        $days = min((int) $request->query('days', 14), 90);

        $invoices = Invoice::query()
            ->with('customer:id,name,phone')
            ->whereDate('invoice_date', '>=', now()->subDays($days)->toDateString())
            ->whereNotIn('id', CustomerFeedback::query()->whereNotNull('invoice_id')->pluck('invoice_id'))
            ->whereHas('customer', fn ($c) => $c->whereNotNull('phone')->where('phone', '!=', ''))
            ->orderByDesc('invoice_date')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $invoices->map(fn (Invoice $i) => [
                'id' => $i->id,
                'invoice_number' => $i->invoice_number,
                'invoice_date' => $i->invoice_date?->toDateString(),
                'total' => $i->total,
                'customer' => $i->customer?->name,
                'phone' => $i->customer?->phone,
            ]),
        ]);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * The score plus the two breakdowns a shop acts on: how the five ratings are
     * spread (an average of 4.1 made of fives and ones is a different shop from
     * one made entirely of fours), and what the complaints are about.
     */
    private function summary($query): array
    {
        $csat = CustomerFeedback::csat($query);

        $spread = (clone $query)->answered()
            ->selectRaw('rating, COUNT(*) as c')
            ->groupBy('rating')
            ->pluck('c', 'rating');

        $tagCounts = [];
        foreach ((clone $query)->answered()->whereNotNull('tags')->pluck('tags') as $tags) {
            foreach ((array) $tags as $tag) {
                $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
            }
        }
        arsort($tagCounts);

        return $csat + [
            'pending' => (int) (clone $query)->where('status', 'pending')->count(),
            'needs_attention' => (int) (clone $query)
                ->where('status', 'submitted')
                ->where('rating', '<=', CustomerFeedback::DETRACTOR_UPTO)
                ->count(),
            'ratings' => collect(range(1, 5))->mapWithKeys(fn ($r) => [$r => (int) ($spread[$r] ?? 0)])->all(),
            'tags' => array_slice($tagCounts, 0, 8, true),
        ];
    }

    private function validated(Request $request, bool $required): array
    {
        $req = $required ? 'required' : 'sometimes';

        $data = $request->validate([
            'customer_id' => ['nullable', 'exists:customers,id'],
            'customer_name' => ['nullable', 'string', 'max:180'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'invoice_id' => ['nullable', 'exists:invoices,id'],
            'service_job_id' => ['nullable', 'exists:service_jobs,id'],
            'channel' => ['nullable', Rule::in(['counter', 'link', 'sms', 'whatsapp', 'email', 'portal'])],
            'rating' => [$req, 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:40'],
        ]);

        // Denormalise the name/phone so the row survives the customer record.
        if (! empty($data['customer_id']) && empty($data['customer_name'])) {
            $customer = Customer::query()->find($data['customer_id']);
            $data['customer_name'] = $customer?->name;
            $data['customer_phone'] = $data['customer_phone'] ?? $customer?->phone;
        }

        return $data;
    }

    /** Date window, defaulting to the current month so the score means something. */
    private function period(Request $request): array
    {
        return [$request->query('date_from'), $request->query('date_to')];
    }

    /** The shop's name as the customer knows it — same source the SMS module uses. */
    private function businessName(): string
    {
        $context = app(TenantContext::class);

        return $context->has()
            ? (string) (Tenant::find($context->id())?->company_name ?: config('app.name'))
            : (string) config('app.name');
    }

    /**
     * The customer-facing URL. Built off APP_URL rather than the request host:
     * the link is going into an SMS that may be sent by a queued job with no
     * request to read a host from.
     */
    private function publicUrl(CustomerFeedback $feedback): string
    {
        return rtrim((string) config('app.url'), '/')."/feedback/{$feedback->token}";
    }

    private function payload(CustomerFeedback $f): array
    {
        return [
            'id' => $f->id,
            'customer_id' => $f->customer_id,
            'customer' => $f->customer_name ?: $f->customer?->name,
            'customer_phone' => $f->customer_phone ?: $f->customer?->phone,
            'invoice_id' => $f->invoice_id,
            'invoice_number' => $f->invoice?->invoice_number,
            'service_job_id' => $f->service_job_id,
            'job_number' => $f->serviceJob?->job_number,
            'channel' => $f->channel,
            'rating' => $f->rating,
            'comment' => $f->comment,
            'tags' => $f->tags ?? [],
            'status' => $f->status,
            'is_satisfied' => $f->isSatisfied(),
            'needs_attention' => $f->needsAttention(),
            'requested_at' => $f->requested_at,
            'submitted_at' => $f->submitted_at,
            'response' => $f->response,
            'responded_by' => $f->responder?->name,
            'responded_at' => $f->responded_at,
            // Handed back so a counter can copy the link into WhatsApp itself —
            // the click-to-chat module has no server-side send.
            'link' => $f->token ? $this->publicUrl($f) : null,
            'created_at' => $f->created_at,
        ];
    }
}
