<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One row per outbound email attempt (document sends + campaign recipients).
 * Status advances queued -> sent / failed as the send job runs.
 */
class EmailLog extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'mailable', 'related_type', 'related_id', 'campaign_id',
        'to_email', 'to_name', 'subject', 'cc', 'status', 'error', 'sent_at', 'created_by',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'campaign_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function markSent(): void
    {
        $this->update(['status' => 'sent', 'error' => null, 'sent_at' => now()]);
    }

    public function markFailed(string $error): void
    {
        $this->update(['status' => 'failed', 'error' => mb_substr($error, 0, 2000)]);
    }
}
