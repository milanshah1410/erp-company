<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central\Support;

use App\Http\Controllers\Controller;
use App\Models\CentralUser;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketMessage;
use App\Models\Tenant;
use App\Services\Support\SlaPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Support tickets for the SaaS-owner platform panel (Support & Customer
 * Success surface). Operators raise/track issues on behalf of tenants and
 * work them through open → pending → resolved → closed. Guarded by the
 * `tickets.*` central permissions (routes/api.php); closing is its own
 * permission tier (tickets.close) so triage staff can work tickets without
 * being able to end them.
 *
 * Three obligations ride along with the thread (Volume 11 §"Support Tickets"):
 * an **SLA** (tier snapshotted from the tenant's plan, two clocks — see
 * App\Services\Support\SlaPolicy), **attachments** on the ticket or any reply,
 * and a **CSAT rating** captured once the ticket is settled.
 */
class TicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SupportTicket::query()->with(['assignee']);

        if ($status = $request->query('status')) {
            $status === 'open_any'
                ? $query->whereIn('status', ['open', 'pending'])
                : $query->where('status', $status);
        }
        if ($priority = $request->query('priority')) {
            $query->where('priority', $priority);
        }
        if ($tenantId = $request->query('tenant_id')) {
            $query->where('tenant_id', $tenantId);
        }
        if ($assigned = $request->query('assigned_to')) {
            $assigned === 'unassigned'
                ? $query->whereNull('assigned_to')
                : $query->where('assigned_to', $assigned);
        }
        // SLA queue filters. `breached` and `due_soon` are pure date predicates
        // on the stored clocks, so they stay in SQL rather than being computed
        // per-row after pagination (which would return short pages).
        if ($sla = $request->query('sla')) {
            $now = now();
            $soon = $now->copy()->addHours((int) config('support.due_soon_hours', 2));

            $unmetResponse = fn ($q) => $q->whereNull('first_response_at')->whereNotNull('response_due_at');
            $unmetResolution = fn ($q) => $q->whereNull('resolved_at')->whereNull('closed_at')->whereNotNull('resolution_due_at');

            if ($sla === 'breached') {
                $query->where(fn ($q) => $q
                    ->where(fn ($r) => $unmetResponse($r)->where('response_due_at', '<', $now))
                    ->orWhere(fn ($r) => $unmetResolution($r)->where('resolution_due_at', '<', $now)));
            } elseif ($sla === 'due_soon') {
                $query->where(fn ($q) => $q
                    ->where(fn ($r) => $unmetResponse($r)->whereBetween('response_due_at', [$now, $soon]))
                    ->orWhere(fn ($r) => $unmetResolution($r)->whereBetween('resolution_due_at', [$now, $soon])));
            } elseif ($sla === 'rated') {
                $query->whereNotNull('rating');
            } elseif ($sla === 'unrated') {
                $query->whereNull('rating')->whereIn('status', ['resolved', 'closed']);
            }
        }
        if ($search = trim((string) $request->query('search', ''))) {
            // Tenant may live on another connection — resolve matching tenant
            // ids first instead of a cross-connection whereHas subquery.
            $tenantIds = Tenant::query()
                ->where(fn ($q) => $q
                    ->where('company_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%"))
                ->pluck('id');
            $query->where(fn ($q) => $q
                ->where('subject', 'like', "%{$search}%")
                ->orWhereIn('tenant_id', $tenantIds));
        }

        $perPage = min((int) $request->query('per_page', 15), 100);
        $page = $query
            ->orderByRaw("field(status, 'open', 'pending', 'resolved', 'closed')")
            ->orderByRaw("field(priority, 'urgent', 'high', 'normal', 'low')")
            ->orderByDesc('last_activity_at')
            ->paginate($perPage);

        $tenantNames = Tenant::query()
            ->whereIn('id', collect($page->items())->pluck('tenant_id')->unique())
            ->pluck('company_name', 'id');

        // Triage counters over the whole table, not just the current page.
        $byStatus = SupportTicket::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return response()->json([
            'data' => collect($page->items())
                ->map(fn (SupportTicket $t) => $this->payload($t, $tenantNames[$t->tenant_id] ?? null))
                ->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'summary' => [
                'open' => (int) ($byStatus['open'] ?? 0),
                'pending' => (int) ($byStatus['pending'] ?? 0),
                'resolved' => (int) ($byStatus['resolved'] ?? 0),
                'closed' => (int) ($byStatus['closed'] ?? 0),
                'unassigned' => SupportTicket::query()
                    ->whereIn('status', ['open', 'pending'])
                    ->whereNull('assigned_to')
                    ->count(),
            ] + $this->slaSummary(),
        ]);
    }

    /**
     * SLA + CSAT headline numbers for the queue banner. Compliance is measured
     * over settled tickets only — a ticket still in flight has not passed or
     * failed its resolution target yet, and counting it either way would make
     * the number drift with queue depth rather than with performance.
     *
     * @return array<string, mixed>
     */
    private function slaSummary(): array
    {
        $now = now();

        $liveBreached = SupportTicket::query()
            ->whereIn('status', ['open', 'pending'])
            ->where(fn ($q) => $q
                ->where(fn ($r) => $r->whereNull('first_response_at')->where('response_due_at', '<', $now))
                ->orWhere('resolution_due_at', '<', $now))
            ->count();

        $settled = SupportTicket::query()
            ->whereIn('status', ['resolved', 'closed'])
            ->whereNotNull('resolution_due_at')
            ->selectRaw('count(*) as total, sum(coalesce(resolved_at, closed_at) <= resolution_due_at) as met')
            ->first();

        $rated = SupportTicket::query()
            ->whereNotNull('rating')
            ->selectRaw('count(*) as total, avg(rating) as average')
            ->first();

        $total = (int) ($settled->total ?? 0);

        return [
            'sla_breached_open' => $liveBreached,
            'sla_settled' => $total,
            'sla_compliance' => $total > 0 ? round(((int) $settled->met / $total) * 100, 1) : null,
            'ratings_count' => (int) ($rated->total ?? 0),
            'rating_average' => $rated->average !== null ? round((float) $rated->average, 2) : null,
            'awaiting_rating' => SupportTicket::query()
                ->whereIn('status', ['resolved', 'closed'])
                ->whereNull('rating')
                ->count(),
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'integer', Rule::exists(Tenant::class, 'id')],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'category' => ['sometimes', Rule::in(SupportTicket::CATEGORIES)],
            'priority' => ['sometimes', Rule::in(SupportTicket::PRIORITIES)],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            // Model-based exists so the rule follows CentralUser's connection.
            'assigned_to' => ['nullable', 'integer', Rule::exists(CentralUser::class, 'id')],
            'files' => ['sometimes', 'array', 'max:'.(int) config('support.attachments.max_per_ticket')],
            'files.*' => $this->attachmentRules(),
        ]);

        $operator = $request->user();

        $ticket = new SupportTicket([
            'tenant_id' => $data['tenant_id'],
            'subject' => $data['subject'],
            'category' => $data['category'] ?? 'general',
            'priority' => $data['priority'] ?? 'normal',
            'status' => 'open',
            'contact_name' => $data['contact_name'] ?? null,
            'contact_email' => $data['contact_email'] ?? null,
            'assigned_to' => $data['assigned_to'] ?? null,
            'created_by' => $operator->id,
            'last_activity_at' => now(),
        ]);

        // Snapshot the tenant's SLA tier and stamp both clocks from now — the
        // ticket has no created_at until it is saved.
        $ticket->sla_tier = SlaPolicy::tierFor((int) $data['tenant_id']);
        SlaPolicy::stamp($ticket, now());
        $ticket->save();

        $ticket->messages()->create([
            'author_type' => 'operator',
            'author_name' => $operator->name,
            'central_user_id' => $operator->id,
            'body' => $data['body'],
        ]);

        foreach ($request->file('files', []) as $file) {
            $this->storeAttachment($ticket, $file, $operator->id);
        }

        activity('central-support')->causedBy($operator)
            ->withProperties(['ip' => $request->ip(), 'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id])
            ->event('ticket-created')->log("Operator opened support ticket \"{$ticket->subject}\"");

        return response()->json(['data' => $this->payload($ticket->load('assignee'), $ticket->tenant?->company_name)], 201);
    }

    public function show(SupportTicket $ticket): JsonResponse
    {
        $ticket->load([
            'assignee', 'creator', 'attachments',
            'messages' => fn ($q) => $q->orderBy('created_at')->with('attachments'),
        ]);

        return response()->json(['data' => $this->payload($ticket, $ticket->tenant?->company_name, withThread: true)]);
    }

    /**
     * Triage edits: status/priority/category, reassignment, subject fixes.
     * Closing goes through close() so it can carry its own permission.
     */
    public function update(Request $request, SupportTicket $ticket): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['sometimes', 'string', 'max:255'],
            'category' => ['sometimes', Rule::in(SupportTicket::CATEGORIES)],
            'priority' => ['sometimes', Rule::in(SupportTicket::PRIORITIES)],
            'status' => ['sometimes', Rule::in(['open', 'pending', 'resolved'])],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'assigned_to' => ['nullable', 'integer', Rule::exists(CentralUser::class, 'id')],
        ]);

        $changes = collect($data)
            ->filter(fn ($v, $k) => $ticket->{$k} != $v)
            ->map(fn ($v, $k) => [$ticket->{$k}, $v]);

        $priorityChanged = array_key_exists('priority', $data) && $data['priority'] !== $ticket->priority;

        $ticket->fill($data);
        if (array_key_exists('status', $data)) {
            $ticket->resolved_at = $data['status'] === 'resolved' ? now() : null;
            $ticket->closed_at = null; // triage statuses always reopen a closed ticket
        }
        // Re-prioritising moves the SLA target with it. Both clocks still run
        // from the ticket's creation, so an escalation shortens the window it
        // already had instead of granting a fresh one.
        if ($priorityChanged) {
            SlaPolicy::stamp($ticket);
        }
        if ($changes->isNotEmpty()) {
            $ticket->last_activity_at = now();
        }
        $ticket->save();

        activity('central-support')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'ticket_id' => $ticket->id, 'changes' => $changes])
            ->event('ticket-updated')->log("Operator updated support ticket \"{$ticket->subject}\"");

        return response()->json(['data' => $this->payload($ticket->load('assignee'), $ticket->tenant?->company_name)]);
    }

    /**
     * Append to the conversation thread. `is_internal` notes are operator-only;
     * `author_type` tenant lets an operator transcribe a customer reply that
     * arrived out-of-band (email/phone). Files may ride along with the reply and
     * are pinned to the message so the thread can render them in place.
     */
    public function reply(Request $request, SupportTicket $ticket): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'is_internal' => ['sometimes', 'boolean'],
            'author_type' => ['sometimes', Rule::in(['operator', 'tenant'])],
            'files' => ['sometimes', 'array', 'max:'.(int) config('support.attachments.max_per_ticket')],
            'files.*' => $this->attachmentRules(),
        ]);

        $operator = $request->user();
        $asTenant = ($data['author_type'] ?? 'operator') === 'tenant';
        $isInternal = (bool) ($data['is_internal'] ?? false);

        $message = $ticket->messages()->create([
            'author_type' => $asTenant ? 'tenant' : 'operator',
            'author_name' => $asTenant ? ($ticket->contact_name ?: 'Customer') : $operator->name,
            'central_user_id' => $asTenant ? null : $operator->id,
            'is_internal' => $isInternal,
            'body' => $data['body'],
        ]);

        foreach ($request->file('files', []) as $file) {
            $this->storeAttachment($ticket, $file, $operator->id, $message->id);
        }

        // The SLA response clock stops at the first customer-visible reply from
        // our side. An internal note is not an answer, and a transcribed
        // customer message is the customer talking — neither discharges it.
        if (! $asTenant && ! $isInternal && ! $ticket->first_response_at) {
            $ticket->first_response_at = now();
        }

        // A resolved/closed ticket that gets a customer reply is live again.
        $ticket->last_activity_at = now();
        if (! $ticket->isOpen()) {
            $ticket->status = 'open';
            $ticket->resolved_at = null;
            $ticket->closed_at = null;
        }
        $ticket->save();

        return response()->json([
            'data' => $this->messagePayload($message->load('attachments')),
            'status' => $ticket->status,
        ], 201);
    }

    public function close(Request $request, SupportTicket $ticket): JsonResponse
    {
        if ($ticket->status === 'closed') {
            return response()->json(['message' => 'Ticket is already closed.'], 422);
        }

        $data = $request->validate(['note' => ['nullable', 'string', 'max:10000']]);
        $operator = $request->user();

        if (! empty($data['note'])) {
            $ticket->messages()->create([
                'author_type' => 'operator',
                'author_name' => $operator->name,
                'central_user_id' => $operator->id,
                'body' => $data['note'],
            ]);
        }

        $ticket->update([
            'status' => 'closed',
            'resolved_at' => $ticket->resolved_at ?? now(),
            'closed_at' => now(),
            'last_activity_at' => now(),
        ]);

        activity('central-support')->causedBy($operator)
            ->withProperties(['ip' => $request->ip(), 'ticket_id' => $ticket->id])
            ->event('ticket-closed')->log("Operator closed support ticket \"{$ticket->subject}\"");

        return response()->json(['data' => $this->payload($ticket->load('assignee'), $ticket->tenant?->company_name)]);
    }

    /**
     * Attach one or more files to the ticket itself (as opposed to a reply).
     */
    public function attach(Request $request, SupportTicket $ticket): JsonResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => $this->attachmentRules(),
        ]);

        $max = (int) config('support.attachments.max_per_ticket');
        $existing = $ticket->attachments()->count();
        if ($existing + count($request->file('files')) > $max) {
            return response()->json([
                'message' => "A ticket can hold at most {$max} attachments ({$existing} already stored).",
            ], 422);
        }

        $stored = collect($request->file('files'))
            ->map(fn ($file) => $this->attachmentPayload(
                $this->storeAttachment($ticket, $file, $request->user()->id),
            ))
            ->values();

        $ticket->update(['last_activity_at' => now()]);

        return response()->json(['data' => $stored], 201);
    }

    /** Stream one attachment back. Guarded by tickets.view, like the thread. */
    public function downloadAttachment(SupportTicket $ticket, SupportTicketAttachment $attachment): StreamedResponse
    {
        abort_unless($attachment->support_ticket_id === $ticket->id, 404);

        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists($attachment->path), 404, 'The stored file is missing.');

        return $disk->download($attachment->path, $attachment->original_name);
    }

    /** Remove an attachment (row + file). Needs tickets.edit. */
    public function destroyAttachment(SupportTicket $ticket, SupportTicketAttachment $attachment): JsonResponse
    {
        abort_unless($attachment->support_ticket_id === $ticket->id, 404);

        $attachment->deleteFile();
        $attachment->delete();

        return response()->json(['message' => 'Attachment removed.']);
    }

    /**
     * Record the customer's satisfaction score. Only a settled ticket can be
     * rated — CSAT asks "was this handled well", which is unanswerable while
     * the thread is still running. Re-rating overwrites: support routinely
     * transcribes a score that arrived by email after a first guess.
     */
    public function rate(Request $request, SupportTicket $ticket): JsonResponse
    {
        if (! $ticket->isSettled()) {
            return response()->json(['message' => 'Only a resolved or closed ticket can be rated.'], 422);
        }

        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $ticket->update([
            'rating' => $data['rating'],
            'rating_comment' => $data['comment'] ?? null,
            'rated_at' => now(),
        ]);

        activity('central-support')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'ticket_id' => $ticket->id, 'rating' => $data['rating']])
            ->event('ticket-rated')->log("Support ticket \"{$ticket->subject}\" rated {$data['rating']}/5");

        return response()->json(['data' => $this->payload($ticket->load('assignee'), $ticket->tenant?->company_name)]);
    }

    /**
     * Active operators for the assignee dropdown. Deliberately lighter than
     * /operators (id + name only) so ticket staff don't need operators.view.
     */
    public function agents(): JsonResponse
    {
        return response()->json([
            'data' => CentralUser::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    /**
     * Validation for one uploaded file, driven by config/support.php so the
     * limits are tuned in one place.
     *
     * @return array<int, mixed>
     */
    private function attachmentRules(): array
    {
        return [
            'file',
            'max:'.(int) config('support.attachments.max_kb'),
            'mimes:'.implode(',', (array) config('support.attachments.mimes')),
        ];
    }

    /**
     * Put one uploaded file on the central support disk and record it.
     * Files are foldered per ticket so a ticket's evidence stays together and a
     * purge is a single directory delete.
     */
    private function storeAttachment(
        SupportTicket $ticket,
        UploadedFile $file,
        ?int $operatorId,
        ?int $messageId = null,
    ): SupportTicketAttachment {
        $disk = (string) config('support.attachments.disk');
        $dir = trim((string) config('support.attachments.directory'), '/')."/{$ticket->id}";

        return SupportTicketAttachment::create([
            'support_ticket_id' => $ticket->id,
            'support_ticket_message_id' => $messageId,
            'disk' => $disk,
            'path' => Storage::disk($disk)->putFile($dir, $file),
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $operatorId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(SupportTicket $t, ?string $tenantName, bool $withThread = false): array
    {
        $sla = $t->sla();

        $row = [
            'id' => $t->id,
            'tenant_id' => $t->tenant_id,
            'tenant_name' => $tenantName,
            'subject' => $t->subject,
            'category' => $t->category,
            'status' => $t->status,
            'priority' => $t->priority,
            'contact_name' => $t->contact_name,
            'contact_email' => $t->contact_email,
            'assigned_to' => $t->assigned_to,
            'assignee_name' => $t->assignee?->name,
            'last_activity_at' => $t->last_activity_at?->toDateTimeString(),
            'resolved_at' => $t->resolved_at?->toDateTimeString(),
            'closed_at' => $t->closed_at?->toDateTimeString(),
            'created_at' => $t->created_at?->toDateTimeString(),
            'sla' => [
                'tier' => $t->sla_tier,
                'tier_label' => SlaPolicy::tierLabel((string) $t->sla_tier),
                'state' => $sla['state'],
                'response' => $sla['response'],
                'resolution' => $sla['resolution'],
                'minutes_to_due' => $sla['minutes_to_due'],
                'response_due_at' => $t->response_due_at?->toDateTimeString(),
                'resolution_due_at' => $t->resolution_due_at?->toDateTimeString(),
                'first_response_at' => $t->first_response_at?->toDateTimeString(),
            ],
            'rating' => $t->rating,
            'rating_comment' => $t->rating_comment,
            'rated_at' => $t->rated_at?->toDateTimeString(),
        ];

        if ($withThread) {
            $row['created_by_name'] = $t->creator?->name;
            $row['messages'] = $t->messages->map(fn ($m) => $this->messagePayload($m))->values();
            // Ticket-level files (those not pinned to a reply) render as their
            // own strip above the thread.
            $row['attachments'] = $t->attachments
                ->whereNull('support_ticket_message_id')
                ->map(fn ($a) => $this->attachmentPayload($a))
                ->values();
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function messagePayload(SupportTicketMessage $m): array
    {
        return [
            'id' => $m->id,
            'author_type' => $m->author_type,
            'author_name' => $m->author_name,
            'is_internal' => $m->is_internal,
            'body' => $m->body,
            'created_at' => $m->created_at?->toDateTimeString(),
            'attachments' => $m->relationLoaded('attachments')
                ? $m->attachments->map(fn ($a) => $this->attachmentPayload($a))->values()
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attachmentPayload(SupportTicketAttachment $a): array
    {
        return [
            'id' => $a->id,
            'name' => $a->original_name,
            'mime_type' => $a->mime_type,
            'size' => $a->size,
            'is_image' => $a->isImage(),
            'message_id' => $a->support_ticket_message_id,
            'uploaded_at' => $a->created_at?->toDateTimeString(),
        ];
    }
}
