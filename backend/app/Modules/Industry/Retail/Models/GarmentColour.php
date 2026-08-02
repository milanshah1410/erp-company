<?php

declare(strict_types=1);

namespace App\Modules\Industry\Retail\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One colour the shop stocks in — the other axis of the matrix.
 *
 * Individual rows rather than a set (the way sizes are held) because colours are
 * picked one at a time per style, carry a swatch, and are added to over a
 * season: nobody "orders the colour run", they add Mustard in August.
 */
class GarmentColour extends Model
{
    use BelongsToTenant;

    protected $fillable = ['name', 'code', 'hex', 'sort_order', 'is_active'];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /** The colours most shops start from, as code => [name, hex]. */
    public const STARTERS = [
        'BLK' => ['Black', '#111111'],
        'WHT' => ['White', '#FFFFFF'],
        'NVY' => ['Navy', '#1F3864'],
        'GRY' => ['Grey', '#8C8C8C'],
        'BEG' => ['Beige', '#D8C3A5'],
        'MRN' => ['Maroon', '#7B1E28'],
        'OLV' => ['Olive', '#5B6236'],
        'SKY' => ['Sky Blue', '#87BDD8'],
    ];

    protected static function booted(): void
    {
        static::saving(function (GarmentColour $colour) {
            // The code becomes an SKU suffix, so it is normalised on the way in
            // rather than at every call site that builds one.
            if ($colour->code) {
                $colour->code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $colour->code) ?: '');
            }
        });
    }
}
