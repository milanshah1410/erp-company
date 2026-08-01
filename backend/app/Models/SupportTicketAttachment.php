<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A file on a platform support ticket — a screenshot, an error log, a signed
 * quote. Central data, so it lives on the central disk (config `support
 * .attachments.disk`) and NOT under App\Support\TenantStorage's per-tenant
 * prefix: the file belongs to the platform↔tenant conversation, not to the
 * tenant's own business records.
 *
 * The disk is private in every environment; the only way out is the
 * permission-checked download route.
 */
class SupportTicketAttachment extends Model
{
    use UsesCentralConnection;

    protected $fillable = [
        'support_ticket_id', 'support_ticket_message_id',
        'disk', 'path', 'original_name', 'mime_type', 'size', 'uploaded_by',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(SupportTicketMessage::class, 'support_ticket_message_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(CentralUser::class, 'uploaded_by');
    }

    /** Whether the browser can render this inline (drives the thumbnail strip). */
    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }

    /** Remove the stored file; missing files are not an error on delete. */
    public function deleteFile(): void
    {
        Storage::disk($this->disk)->delete($this->path);
    }
}
