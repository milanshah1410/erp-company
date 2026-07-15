<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable marketing email blast: a subject + HTML body sent to a resolved
 * audience (all customers with an email, a customer category, or a manual list).
 * Per-recipient delivery is tracked through the related EmailLog rows.
 */
class MarketingCampaign extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    public const AUDIENCE_ALL = 'all';

    public const AUDIENCE_CATEGORY = 'category';

    public const AUDIENCE_MANUAL = 'manual';

    protected $fillable = [
        'tenant_id', 'name', 'subject', 'body', 'audience', 'customer_category_id',
        'manual_recipients', 'status', 'recipient_count', 'sent_count', 'failed_count',
        'sent_at', 'created_by',
    ];

    protected $casts = [
        'manual_recipients' => 'array',
        'sent_at' => 'datetime',
    ];

    public function customerCategory(): BelongsTo
    {
        return $this->belongsTo(CustomerCategory::class);
    }

    public function emailLogs(): HasMany
    {
        return $this->hasMany(EmailLog::class, 'campaign_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Resolve the recipient list [['email' => ..., 'name' => ...], ...] for this
     * campaign's audience, de-duplicated by email and skipping blanks.
     */
    public function resolveRecipients(): array
    {
        $recipients = match ($this->audience) {
            self::AUDIENCE_MANUAL => collect($this->manual_recipients ?? [])
                ->map(fn ($r) => ['email' => $r['email'] ?? null, 'name' => $r['name'] ?? null]),
            self::AUDIENCE_CATEGORY => Customer::query()
                ->where('customer_category_id', $this->customer_category_id)
                ->whereNotNull('email')->where('email', '!=', '')
                ->get(['name', 'email'])
                ->map(fn ($c) => ['email' => $c->email, 'name' => $c->name]),
            default => Customer::query()
                ->whereNotNull('email')->where('email', '!=', '')
                ->get(['name', 'email'])
                ->map(fn ($c) => ['email' => $c->email, 'name' => $c->name]),
        };

        return $recipients
            ->filter(fn ($r) => ! empty($r['email']) && filter_var($r['email'], FILTER_VALIDATE_EMAIL))
            ->unique('email')
            ->values()
            ->all();
    }
}
