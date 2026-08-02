<?php

declare(strict_types=1);

namespace App\Modules\Industry\Toys\Models;

use App\Models\Brand;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One piece of paper: a BIS licence, an EN 71 test report, a CE declaration.
 *
 * The register exists because of the date on it. Under the Toys (Quality
 * Control) Order a toy may not be sold in India without a live BIS licence
 * behind it, and a licence expires quietly — the article on the shelf looks
 * exactly the same the day after as the day before. A shop finds out during an
 * inspection, which is the most expensive possible way to be told.
 *
 * Scope is the second thing this table gets right. A licence is issued to a
 * manufacturer for a range, not to a shop for a SKU, so a certificate may be
 * attached to a product, a brand or a supplier — and the narrowest one wins, so
 * that a specific test report for one article overrides the blanket licence
 * covering everything that supplier sends.
 */
class SafetyCertificate extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $table = 'toy_safety_certificates';

    protected $fillable = [
        'certificate_number', 'standard',
        'product_id', 'brand_id', 'supplier_id',
        'issued_by', 'licence_no', 'issued_on', 'expires_on',
        'document_path', 'status', 'notes', 'created_by',
    ];

    protected $casts = [
        'issued_on' => 'date',
        'expires_on' => 'date',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'standard' => 'bis',
        'status' => 'active',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Is this certificate good on the given day?
     *
     * Derived, never stored. A row sitting at 'active' with last month's expiry
     * date is the normal state of any register nobody sweeps, and the counter
     * must be told the truth without waiting for a nightly job to notice.
     */
    public function isLive(?Carbon $on = null): bool
    {
        if ($this->status === 'revoked') {
            return false;
        }

        $on ??= now();

        if ($this->issued_on && $on->lt($this->issued_on->startOfDay())) {
            return false;
        }

        // No expiry is an open-ended declaration, not a missing value — a CE
        // self-declaration genuinely never runs out.
        return $this->expires_on === null || $on->startOfDay()->lte($this->expires_on->startOfDay());
    }

    /** Days left, negative once it has lapsed; null for an open-ended one. */
    public function daysRemaining(?Carbon $on = null): ?int
    {
        if ($this->expires_on === null) {
            return null;
        }

        return (int) ($on ?? now())->startOfDay()->diffInDays($this->expires_on->startOfDay(), false);
    }

    /** What the register shows, with the date taken into account. */
    public function derivedStatus(): string
    {
        if ($this->status === 'revoked') {
            return 'revoked';
        }

        return $this->isLive() ? 'active' : 'expired';
    }

    /** Why this one does not cover a sale today — null when it does. */
    public function lapseReason(): ?string
    {
        return match (true) {
            $this->status === 'revoked' => 'Certificate '.$this->certificate_number.' was withdrawn.',
            $this->isLive() => null,
            $this->issued_on && now()->lt($this->issued_on->startOfDay()) => 'Certificate '.$this->certificate_number.' does not take effect until '.$this->issued_on->toDateString().'.',
            default => 'Certificate '.$this->certificate_number.' expired on '.$this->expires_on?->toDateString().'.',
        };
    }

    /** Certificates whose expiry falls inside the next `$days` (or already has). */
    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->where('status', 'active')
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<=', now()->addDays($days));
    }

    /**
     * Every certificate that could cover this article, narrowest scope first.
     *
     * Ordering is the whole method: the caller takes the first live one, so a
     * product-specific report is preferred to the brand licence, and the brand
     * licence to whatever blanket paper the supplier sent. A shop that has both
     * should be quoting the specific one.
     */
    public function scopeCovering(Builder $query, Product $product): Builder
    {
        return $query
            ->where(function (Builder $q) use ($product) {
                $q->where('product_id', $product->id);

                if ($product->brand_id) {
                    $q->orWhere('brand_id', $product->brand_id);
                }

                if ($product->preferred_supplier_id) {
                    $q->orWhere('supplier_id', $product->preferred_supplier_id);
                }
            })
            ->orderByRaw('CASE WHEN product_id IS NOT NULL THEN 0 WHEN brand_id IS NOT NULL THEN 1 ELSE 2 END')
            ->orderByRaw('expires_on IS NULL DESC')
            ->orderByDesc('expires_on');
    }

    /**
     * What the certificate is written against — "Article", "Brand", "Supplier".
     *
     * Not named scopeSomething, however much it reads like one: Eloquent claims
     * every method with that prefix as a query scope.
     */
    public function coverageLabel(): string
    {
        return match (true) {
            $this->product_id !== null => 'Article',
            $this->brand_id !== null => 'Brand',
            $this->supplier_id !== null => 'Supplier',
            default => 'Unscoped',
        };
    }
}
