<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central\Support;

use App\Http\Controllers\Controller;
use App\Models\CentralUser;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Support tickets for the SaaS-owner platform panel (Support & Customer
 * Success surface). Operators raise/track issues on behalf of tenants and
 * work them through open → pending → resolved → closed. Guarded by the
 * `tickets.*` central permissions (routes/api.php); closing is its own
 * permission tier (tickets.close) so triage staff can work tickets without
 * being able to end them.
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
            ],
        ]);
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
        ]);

        $operator = $request->user();

        $ticket = SupportTicket::create([
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

        $ticket->messages()->create([
            'author_type' => 'operator',
            'author_name' => $operator->name,
            'central_user_id' => $operator->id,
            'body' => $data['body'],
        ]);

        activity('central-support')->causedBy($operator)
            ->withProperties(['ip' => $request->ip(), 'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id])
            ->event('ticket-created')->log("Operator opened support ticket \"{$ticket->subject}\"");

        return response()->json(['data' => $this->payload($ticket->load('assignee'), $ticket->tenant?->company_name)], 201);
    }

    public function show(SupportTicket $ticket): JsonResponse
    {
        $ticket->load(['assignee', 'creator', 'messages' => fn ($q) => $q->orderBy('created_at')]);

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

        $ticket->fill($data);
        if (array_key_exists('status', $data)) {
            $ticket->resolved_at = $data['status'] === 'resolved' ? now() : null;
            $ticket->closed_at = null; // triage statuses always reopen a closed ticket
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
     * arrived out-of-band (email/phone).
     */
    public function reply(Request $request, SupportTicket $ticket): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'is_internal' => ['sometimes', 'boolean'],
            'author_type' => ['sometimes', Rule::in(['operator', 'tenant'])],
        ]);

        $operator = $request->user();
        $asTenant = ($data['author_type'] ?? 'operator') === 'tenant';

        $message = $ticket->messages()->create([
            'author_type' => $asTenant ? 'tenant' : 'operator',
            'author_name' => $asTenant ? ($ticket->contact_name ?: 'Customer') : $operator->name,
            'central_user_id' => $asTenant ? null : $operator->id,
            'is_internal' => (bool) ($data['is_internal'] ?? false),
            'body' => $data['body'],
        ]);

        // A resolved/closed ticket that gets a customer reply is live again.
        $ticket->last_activity_at = now();
        if (! $ticket->isOpen()) {
            $ticket->status = 'open';
            $ticket->resolved_at = null;
            $ticket->closed_at = null;
        }
        $ticket->save();

        return response()->json(['data' => $this->messagePayload($message), 'status' => $ticket->status], 201);
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
     * @return array<string, mixed>
     */
    private function payload(SupportTicket $t, ?string $tenantName, bool $withThread = false): array
    {
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
        ];

        if ($withThread) {
            $row['created_by_name'] = $t->creator?->name;
            $row['messages'] = $t->messages->map(fn ($m) => $this->messagePayload($m))->values();
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
        ];
    }
}
