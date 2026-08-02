<?php

declare(strict_types=1);

namespace App\Modules\Industry\Cosmetics\Models;

use App\Models\Brand;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One shade on the shop's card — "NC42", "015 Nude Blush", "Ruby Woo".
 *
 * A shade is not a colour, and the difference is the reason this master exists
 * rather than a string in the variant's attributes. A garment colour is chosen
 * by looking at it; a shade is chosen by matching it to a face, and the four
 * fields that decide the match — family, finish, undertone, depth — are what a
 * customer is really asking about when they say "something like this, but
 * lighter". A shop that cannot answer that in front of the customer sends them
 * to the counter that can.
 *
 * The second reason is stock. A foundation range is forty shades and it never
 * sells evenly: the middle of the depth scale goes first and the shop is
 * perpetually out of four of them. That question — which shades of this range
 * are missing right now — is unanswerable if the shade is a word inside a JSON
 * column, and it is the question the shade card screen exists to answer.
 */
class CosmeticShade extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $table = 'cosmetic_shades';

    protected $fillable = [
        'code', 'name', 'brand_id', 'family', 'finish', 'undertone',
        'depth_level', 'hex', 'description', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'brand_id' => 'integer',
        'depth_level' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'finish' => 'matte',
        'undertone' => 'unknown',
        'is_active' => true,
    ];

    /** The closed vocabularies. Exposed so the API and the form share one list. */
    public const FINISHES = ['matte', 'satin', 'cream', 'gloss', 'shimmer', 'metallic', 'sheer', 'other'];

    public const UNDERTONES = ['warm', 'cool', 'neutral', 'olive', 'unknown'];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Articles keyed one-SKU-per-shade, and variants generated from the shade
     * card. Both are declared here rather than on the core models so the
     * dependency points plugin → core and never back; the reverse relations are
     * attached at boot by CosmeticsPlugin.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'cosmetic_shade_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'cosmetic_shade_id');
    }

    /**
     * Card order: depth first where the range has one, then the shop's own
     * ordering, then the code.
     *
     * Depth leads because a base range read in any other order is unusable —
     * the whole point of a foundation card is that it runs light to deep. Ranges
     * with no depth (a lipstick wall) fall through to sort_order and read the
     * way the shop laid them out.
     */
    public function scopeCardOrder(Builder $query): Builder
    {
        return $query->orderByRaw('depth_level IS NULL, depth_level ASC')
            ->orderBy('sort_order')
            ->orderBy('code');
    }

    /** "015 Nude Blush", the way it is written on a label. */
    public function displayName(): string
    {
        return trim($this->code.' '.$this->name);
    }

    /**
     * The one-line description a counter reads out: "Nude · matte · warm".
     *
     * Assembled rather than stored because every part of it is separately
     * editable, and a stored sentence would go stale the first time somebody
     * corrected the undertone.
     */
    public function descriptor(): string
    {
        $parts = array_filter([
            $this->family,
            $this->finish,
            $this->undertone !== 'unknown' ? $this->undertone : null,
            $this->depth_level ? 'depth '.$this->depth_level : null,
        ]);

        return implode(' · ', $parts);
    }
}
