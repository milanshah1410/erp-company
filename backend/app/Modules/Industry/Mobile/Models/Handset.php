<?php

declare(strict_types=1);

namespace App\Modules\Industry\Mobile\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductWarranty;
use App\Models\ServiceJob;
use App\Models\User;
use App\Modules\Industry\Mobile\Support\Imei;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One handset, by IMEI — the aftersales record everything in this trade hangs off.
 *
 * Deliberately not the core `serial_numbers` row. That one is an inventory device:
 * which serial sits in which warehouse, what document last moved it, consumed by
 * stock. This one answers the questions asked *after* the box leaves the shop —
 * who owns it, when it was sold, what covers it, what has been done to it — and it
 * exists for handsets that never passed through this shop's stock at all, which is
 * most of the repair and insurance business.
 *
 * Both tables can name the same IMEI. They answer different questions, and the
 * same reasoning is written out at more length in the Electronics vertical, whose
 * unit register is this one's sibling.
 */
class Handset extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $table = 'mobile_handsets';

    protected $fillable = [
        'customer_id', 'product_id', 'brand', 'model_name', 'storage', 'colour',
        'imei_1', 'imei_2', 'serial_no',
        'status', 'is_third_party', 'sale_invoice_id', 'sold_at',
        'warranty_months', 'last_service_at', 'notes', 'is_active', 'created_by',
    ];

    protected $casts = [
        'is_third_party' => 'boolean',
        'sold_at' => 'date',
        'warranty_months' => 'integer',
        'last_service_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * Mirrors of the column defaults, so a freshly created instance reads the same
     * as it would after a reload. The Automobile register was bitten by exactly
     * this: a null status in memory made a guard pass by accident.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'in_stock',
        'is_third_party' => false,
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        /**
         * Both IMEIs stripped to digits on the way in. They arrive with spaces,
         * dashes and the occasional slash depending on whose box it is, and a
         * register holding the same number in three punctuations is a register
         * where the counter lookup silently misses.
         */
        static::saving(function (self $handset) {
            $handset->imei_1 = Imei::normalize($handset->imei_1);
            $handset->imei_2 = Imei::normalize($handset->imei_2);
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function saleInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'sale_invoice_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Warranty cards issued on this handset — the *core* register, not a second
     * one. A phone counter and a footwear counter settle a warranty claim the same
     * way, so they share the same table and the same screens.
     */
    public function warranties(): HasMany
    {
        return $this->hasMany(ProductWarranty::class, 'handset_id');
    }

    /** Insurance policies sold on this handset. */
    public function policies(): HasMany
    {
        return $this->hasMany(InsurancePolicy::class, 'handset_id');
    }

    /** Finance files against this handset. */
    public function emiPlans(): HasMany
    {
        return $this->hasMany(EmiPlan::class, 'handset_id');
    }

    /** Repairs booked against this exact handset. */
    public function repairs(): HasMany
    {
        return $this->hasMany(ServiceJob::class, 'handset_id');
    }

    /**
     * Match either IMEI, or the serial — a customer reads out whichever number
     * they managed to find, and on a dual-SIM handset that is as often the second
     * one as the first.
     */
    public function scopeIdentifier(Builder $query, string $value): Builder
    {
        $digits = Imei::normalize($value);
        $raw = trim($value);

        return $query->where(function (Builder $q) use ($digits, $raw) {
            if ($digits !== null) {
                $q->where('imei_1', $digits)->orWhere('imei_2', $digits);
            }

            $q->orWhere('serial_no', $raw);
        });
    }

    public function scopeInStock(Builder $query): Builder
    {
        return $query->where('status', 'in_stock');
    }

    /** Is the primary IMEI well formed? Advisory — see Imei. */
    public function imeiLooksValid(): bool
    {
        return Imei::isValid($this->imei_1);
    }

    /**
     * The standard cover's end date, counted from the sale. Used to fill the
     * warranty card at issue so nobody counts twelve months forward on a calendar
     * — the mistake that puts a card a month out and loses the argument.
     */
    public function warrantyEndsOn(): ?string
    {
        if (! $this->warranty_months || ! $this->sold_at) {
            return null;
        }

        return $this->sold_at->copy()->addMonthsNoOverflow($this->warranty_months)->toDateString();
    }

    /** What the counter says out loud: "Samsung Galaxy A55 · 3517…5628". */
    public function label(): string
    {
        $name = trim((string) $this->brand.' '.(string) $this->model_name);

        if ($name === '') {
            $name = $this->product?->name ?? 'Handset';
        }

        return trim($name.' · '.($this->imei_1 ?? $this->serial_no ?? '—'));
    }
}
