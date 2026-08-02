<?php

declare(strict_types=1);

namespace App\Modules\Industry\Retail\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * An ordered run of sizes — "XS · S · M · L · XL · XXL", or "28 · 30 · 32 · 34".
 *
 * A set rather than a table of individual sizes because sizes are never used
 * individually: they are the columns of the matrix, and their *order* is what
 * makes a size-wise stock report readable. Sorting "S, M, L, XL, XS"
 * alphabetically is the classic way to make one useless.
 */
class GarmentSizeSet extends Model
{
    use BelongsToTenant;

    protected $fillable = ['name', 'sizes', 'is_active'];

    protected $casts = [
        'sizes' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * The size runs a garment shop is expected to have on day one. Seeded per
     * workspace by the plugin's demo fixtures and offered as a starting point in
     * the matrix screen; a shop is free to replace them entirely.
     *
     * @var array<string, list<string>>
     */
    public const STARTERS = [
        'Standard (XS-XXXL)' => ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'],
        'Numeric Waist' => ['28', '30', '32', '34', '36', '38', '40', '42'],
        'Shirt Collar' => ['38', '39', '40', '41', '42', '44', '46'],
        'Free Size' => ['FS'],
        'Kids (Years)' => ['2-3Y', '3-4Y', '4-5Y', '5-6Y', '7-8Y', '9-10Y', '11-12Y'],
    ];

    /**
     * The sizes, cleaned: trimmed, upper-cased, blanks dropped and duplicates
     * removed while keeping the declared order.
     *
     * Read through this rather than the raw cast, because the order is the grid
     * and a stray duplicate would render a column twice and then collide on the
     * variant SKU it generates.
     *
     * @return list<string>
     */
    public function sizeList(): array
    {
        $seen = [];
        foreach ((array) $this->sizes as $size) {
            $clean = strtoupper(trim((string) $size));
            if ($clean !== '' && ! in_array($clean, $seen, true)) {
                $seen[] = $clean;
            }
        }

        return $seen;
    }
}
