<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The shop's rate for one metal at one purity on one day.
 *
 * Dated on purpose: a jewellery bill is priced at the rate of its own date, and
 * the rate card moving tomorrow must never restate an invoice already given to
 * a customer. {@see forDate()} is the only way pricing should reach a rate.
 */
class MetalRate extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    /** Parts per thousand for the purities the trade quotes by name. */
    public const STANDARD_FINENESS = [
        '24K' => 999,
        '23K' => 958,
        '22K' => 916,
        '21K' => 875,
        '18K' => 750,
        '14K' => 585,
        '9K' => 375,
        '999' => 999,   // fine silver
        '925' => 925,   // sterling
        '950' => 950,   // platinum
    ];

    protected $fillable = [
        'metal', 'purity', 'fineness', 'rate_per_gram',
        'buy_rate_per_gram', 'effective_on', 'created_by',
    ];

    protected $casts = [
        'fineness' => 'integer',
        'rate_per_gram' => 'decimal:2',
        'buy_rate_per_gram' => 'decimal:2',
        'effective_on' => 'date',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The rate in force for a metal + purity on a date.
     *
     * Falls back to the most recent earlier rate rather than to nothing: a shop
     * that did not re-quote today is still trading on yesterday's card, and
     * refusing to price a sale over a missing row would be wrong.
     */
    public static function forDate(string $metal, string $purity, ?string $date = null): ?self
    {
        return static::query()
            ->where('metal', $metal)
            ->where('purity', $purity)
            ->where('effective_on', '<=', $date ?? now()->toDateString())
            ->orderByDesc('effective_on')
            ->orderByDesc('id')
            ->first();
    }

    /** What the shop pays for scrap of this purity — the buy rate, or the sell rate. */
    public function buyRate(): float
    {
        return (float) ($this->buy_rate_per_gram ?? $this->rate_per_gram);
    }

    /** Best-known fineness for a purity string, for scrap of an odd karat. */
    public static function finenessFor(string $purity): ?int
    {
        return self::STANDARD_FINENESS[strtoupper($purity)] ?? null;
    }
}
