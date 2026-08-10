<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One customer's verdict on one piece of business (retail base §6).
 *
 * The CSAT convention is encoded here rather than in the controller so every
 * caller counts it the same way: a 4 or a 5 is satisfied, a 3 is neutral and is
 * *not* counted as satisfied, a 1 or 2 is a detractor. Getting that boundary
 * wrong by one point is the difference between a shop believing it scores 80%
 * and knowing it scores 55%.
 */
class CustomerFeedback extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    /** Explicit: Laravel would pluralise this to `customer_feedbacks`. */
    protected $table = 'customer_feedback';

    /** Ratings at or above this are "satisfied" for CSAT. */
    public const SATISFIED_FROM = 4;

    /** Ratings at or below this are complaints the shop should chase. */
    public const DETRACTOR_UPTO = 2;

    protected $fillable = [
        'customer_id', 'customer_name', 'customer_phone',
        'invoice_id', 'service_job_id', 'channel',
        'rating', 'comment', 'tags', 'status',
        'token', 'requested_at', 'submitted_at',
        'response', 'responded_by', 'responded_at', 'created_by',
    ];

    protected $casts = [
        'rating' => 'integer',
        'tags' => 'array',
        'requested_at' => 'datetime',
        'submitted_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    /**
     * A token long enough that guessing one is not a way in, and made of
     * URL-safe characters because it is going into a WhatsApp message.
     */
    public static function newToken(): string
    {
        return Str::random(48);
    }

    public function isSatisfied(): bool
    {
        return $this->rating !== null && $this->rating >= self::SATISFIED_FROM;
    }

    public function isDetractor(): bool
    {
        return $this->rating !== null && $this->rating <= self::DETRACTOR_UPTO;
    }

    /**
     * Feedback that still wants somebody's attention: a poor score nobody has
     * acknowledged. Deliberately not "everything unacknowledged" — a shop that
     * has to tick off four hundred five-star ratings stops ticking anything off.
     */
    public function needsAttention(): bool
    {
        return $this->isDetractor() && in_array($this->status, ['submitted'], true);
    }

    /** Only rows a customer actually answered count towards a score. */
    public function scopeAnswered(Builder $query): Builder
    {
        return $query->whereNotNull('rating')->whereNotNull('submitted_at');
    }

    /**
     * CSAT over a set of rows: the share of answers that were 4 or 5.
     *
     * Takes a query rather than a collection so a year of feedback is counted in
     * the database instead of hydrated into memory.
     */
    public static function csat(Builder $query): array
    {
        $base = (clone $query)->answered();

        $total = (int) (clone $base)->count();
        $satisfied = (int) (clone $base)->where('rating', '>=', self::SATISFIED_FROM)->count();
        $detractors = (int) (clone $base)->where('rating', '<=', self::DETRACTOR_UPTO)->count();
        $average = $total > 0 ? round((float) (clone $base)->avg('rating'), 2) : null;

        return [
            'responses' => $total,
            'satisfied' => $satisfied,
            'detractors' => $detractors,
            'average_rating' => $average,
            'csat_percent' => $total > 0 ? round($satisfied / $total * 100, 1) : null,
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function serviceJob(): BelongsTo
    {
        return $this->belongsTo(ServiceJob::class);
    }

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
