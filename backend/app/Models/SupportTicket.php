<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use App\Services\Support\SlaPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Platform support ticket (Support & Customer Success panel). Central data:
 * one row per tenant issue, with a threaded conversation in
 * SupportTicketMessage. Managed by platform operators (central_users) under
 * the tickets.* Level-1 permissions.
 */
class SupportTicket extends Model
{
    use UsesCentralConnection;

    public const STATUSES = ['open', 'pending', 'resolved', 'closed'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const CATEGORIES = ['general', 'billing', 'technical', 'onboarding', 'data'];

    protected $fillable = [
        'tenant_id', 'subject', 'category', 'status', 'priority',
        'sla_tier', 'response_due_at', 'resolution_due_at', 'first_response_at',
        'contact_name', 'contact_email', 'assigned_to', 'created_by',
        'last_activity_at', 'resolved_at', 'closed_at',
        'rating', 'rating_comment', 'rated_at',
    ];

    protected $casts = [
        'last_activity_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'response_due_at' => 'datetime',
        'resolution_due_at' => 'datetime',
        'first_response_at' => 'datetime',
        'rated_at' => 'datetime',
        'rating' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(CentralUser::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(CentralUser::class, 'created_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SupportTicketAttachment::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['open', 'pending'], true);
    }

    /** Resolved or closed — the resolution clock has stopped either way. */
    public function isSettled(): bool
    {
        return in_array($this->status, ['resolved', 'closed'], true);
    }

    /**
     * SLA verdict for this row (state + per-clock detail).
     *
     * @return array{state: string, response: string, resolution: string, minutes_to_due: ?int}
     */
    public function sla(): array
    {
        return SlaPolicy::state($this);
    }
}
