<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One entry in a support ticket's conversation thread. `author_type` records
 * who spoke (operator | tenant | system); `is_internal` marks operator-only
 * notes that would never be shown to the customer.
 */
class SupportTicketMessage extends Model
{
    use UsesCentralConnection;

    protected $fillable = [
        'support_ticket_id', 'author_type', 'author_name',
        'central_user_id', 'is_internal', 'body',
    ];

    protected $casts = [
        'is_internal' => 'boolean',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    /** Files that arrived with this reply (ticket-level files have no message). */
    public function attachments(): HasMany
    {
        return $this->hasMany(SupportTicketAttachment::class);
    }
}
