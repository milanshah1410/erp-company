<?php

declare(strict_types=1);

namespace App\Modules\Industry\Sports\Models;

use App\Models\Brand;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One identified piece of hire kit: bike 7, the blue 26-inch, the third tent.
 *
 * The register exists because a hire fleet is a set of *things*, not a quantity.
 * A shop with "6 × mountain bike" in stock cannot answer any of the questions it
 * is asked all weekend — which one is free on Sunday, which one has the bent
 * derailleur, which one has already earned its purchase price back — and a
 * quantity cannot be made to answer them however it is decorated.
 *
 * Two rules live on this model rather than in whatever calls it:
 *
 *  - **On hire is derived.** The stored status only carries what nothing else can
 *    know: withdrawn for repair, or written off. Whether the bike is out is a
 *    fact about the contracts, and reading it from them means the fleet board
 *    cannot go stale — which matters, because a fleet board that is wrong about
 *    one bike is worse than no fleet board at all.
 *  - **A rate that is null means not offered.** It is a real answer and a common
 *    one — nobody hires a treadmill by the hour — so the contract refuses a basis
 *    the asset has no rate for rather than quietly billing zero.
 */
class RentalAsset extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $table = 'sports_rental_assets';

    /** Contract statuses that hold an asset — booked ahead, or physically gone. */
    public const HOLDING_STATUSES = ['booked', 'out'];

    /** The rate bases, and the column each one reads. */
    public const RATE_COLUMNS = [
        'hourly' => 'rate_hourly',
        'daily' => 'rate_daily',
        'weekly' => 'rate_weekly',
        'monthly' => 'rate_monthly',
    ];

    protected $fillable = [
        'asset_code', 'name', 'product_id', 'variant_id', 'brand_id', 'warehouse_id',
        'serial_no', 'category',
        'rate_hourly', 'rate_daily', 'rate_weekly', 'rate_monthly',
        'deposit_amount', 'late_fee_percent', 'requires_id_proof',
        'condition', 'status', 'purchase_cost', 'acquired_on',
        'notes', 'is_active', 'created_by',
    ];

    protected $casts = [
        'rate_hourly' => 'decimal:2',
        'rate_daily' => 'decimal:2',
        'rate_weekly' => 'decimal:2',
        'rate_monthly' => 'decimal:2',
        'deposit_amount' => 'decimal:2',
        'purchase_cost' => 'decimal:2',
        'late_fee_percent' => 'integer',
        'requires_id_proof' => 'boolean',
        'is_active' => 'boolean',
        'acquired_on' => 'date',
    ];

    /**
     * Mirrors the column defaults so a freshly created instance answers about
     * itself correctly before it is reloaded. `is_active` in particular: without
     * it the attribute is null on the returned model and `derivedStatus()` reads
     * a brand-new asset as retired.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'condition' => 'good',
        'status' => 'available',
        'late_fee_percent' => 100,
        'deposit_amount' => 0,
        'is_active' => true,
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function rentalItems(): HasMany
    {
        return $this->hasMany(RentalItem::class, 'asset_id');
    }

    /**
     * The rate this asset is hired at on a given basis, or null if it is not
     * offered on that basis.
     */
    public function rateFor(string $basis): ?float
    {
        $column = self::RATE_COLUMNS[$basis] ?? null;

        if (! $column || $this->{$column} === null) {
            return null;
        }

        return (float) $this->{$column};
    }

    /** Every basis this asset is actually published on. @return list<string> */
    public function offeredBases(): array
    {
        return array_values(array_filter(
            array_keys(self::RATE_COLUMNS),
            fn (string $basis) => $this->rateFor($basis) !== null,
        ));
    }

    /**
     * The line that currently has this asset, if any — booked or out, and not yet
     * returned.
     *
     * Deliberately not `->exists()` on the caller's side: the board wants to say
     * *who* has it and *when it is due back*, and a boolean would send the counter
     * hunting through the contracts to find out.
     */
    public function openLine(): ?RentalItem
    {
        return $this->rentalItems()
            ->whereNull('returned_at')
            ->whereHas('rental', fn (Builder $q) => $q->whereIn('status', self::HOLDING_STATUSES))
            ->with('rental:id,rental_number,status,customer_id,due_at,issued_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * What the fleet board shows, with the contracts taken into account.
     *
     * Stored state wins when it is a decision somebody made — a bike on the
     * workbench is not available even if no contract holds it — and the hire is
     * derived underneath.
     */
    public function derivedStatus(?RentalItem $openLine = null): string
    {
        if (in_array($this->status, ['maintenance', 'retired'], true)) {
            return $this->status;
        }

        if (! $this->is_active) {
            return 'retired';
        }

        $line = func_num_args() > 0 ? $openLine : $this->openLine();

        if (! $line) {
            return 'available';
        }

        return $line->rental?->status === 'booked' ? 'booked' : 'on_hire';
    }

    /**
     * Can this go out at all today, before any date arithmetic?
     *
     * The three refusals it can give are the three a counter hears: it is on the
     * bench, it has been written off, or somebody already has it.
     */
    public function unavailableReason(): ?string
    {
        return match ($this->derivedStatus()) {
            'maintenance' => "{$this->asset_code} is on the bench for repair.",
            'retired' => "{$this->asset_code} has been withdrawn from the fleet.",
            'on_hire' => "{$this->asset_code} is out with a customer.",
            'booked' => "{$this->asset_code} is already held on a booking.",
            default => null,
        };
    }

    /** "SPB-07 — Hero Sprint 26\" MTB". */
    public function label(): string
    {
        return "{$this->asset_code} — {$this->name}";
    }

    /** Size and colour, when the asset is a unit of a catalogue variant. */
    public function variantLabel(): ?string
    {
        if (! $this->variant) {
            return null;
        }

        $attributes = $this->variant->attributes ?? [];

        $parts = array_filter([
            $attributes['size'] ?? null,
            $attributes['colour'] ?? $attributes['color'] ?? null,
        ]);

        return $parts === [] ? $this->variant->name : implode(' · ', $parts);
    }

    /** The fleet a counter may actually offer. */
    public function scopeHireable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('status', 'available');
    }
}
