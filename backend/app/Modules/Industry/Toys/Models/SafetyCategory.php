<?php

declare(strict_types=1);

namespace App\Modules\Industry\Toys\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What is dangerous about an article, and what the shop has to hold to sell it.
 *
 * A property of the toy, not of a document: "contains small parts, certified
 * under BIS, not for under-threes" stays true for as long as the article is
 * made that way. That is why it is separate from the certificate register —
 * paperwork lapses, physics does not — and why this row can safely be shared by
 * a hundred SKUs while each of them may sit under a different licence.
 */
class SafetyCategory extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $table = 'toy_safety_categories';

    /**
     * The standards a toy shop in India actually meets a certificate against.
     * `none` is for articles outside the scheme entirely — a jigsaw for adults,
     * a board game with no small components — and exists so that "not certified"
     * can mean "does not need to be" rather than "we never checked".
     */
    public const STANDARDS = [
        'bis' => 'BIS / IS 9873 (mandatory in India)',
        'en71' => 'EN 71 (Europe)',
        'astm' => 'ASTM F963 (USA)',
        'iso8124' => 'ISO 8124',
        'ce' => 'CE marking',
        'none' => 'Not applicable',
    ];

    /**
     * The hazards a toy is withdrawn or refused over. Deliberately the plain
     * language of a recall notice rather than a clause number: the person
     * reading this list is on a shop floor, not in a laboratory.
     */
    public const HAZARDS = [
        'small_parts' => 'Small parts — choking hazard',
        'button_cell' => 'Button cell battery',
        'magnets' => 'Small powerful magnets',
        'projectile' => 'Fires a projectile',
        'long_cord' => 'Long cord or strap',
        'sharp_edges' => 'Sharp edges or points',
        'chemical' => 'Chemicals or paints',
        'flammable' => 'Flammable material',
        'inflatable' => 'Inflatable / water use',
        'electrical' => 'Mains or battery powered',
    ];

    protected $fillable = [
        'code', 'label', 'standard', 'hazards', 'warning_text',
        'min_age_months', 'requires_certificate', 'blocks_sale_without_certificate',
        'sort_order', 'is_active',
    ];

    protected $casts = [
        'hazards' => 'array',
        'min_age_months' => 'integer',
        'requires_certificate' => 'boolean',
        'blocks_sale_without_certificate' => 'boolean',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'standard' => 'bis',
        'requires_certificate' => true,
        'blocks_sale_without_certificate' => false,
        'is_active' => true,
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'toy_safety_category_id');
    }

    /** @return list<string> The hazards spelled out, for a warning label. */
    public function hazardLabels(): array
    {
        return array_values(array_map(
            static fn (string $h) => self::HAZARDS[$h] ?? $h,
            $this->hazards ?? [],
        ));
    }

    /**
     * The sentence to put in front of the customer.
     *
     * Prefers whatever the shop typed, because that is the form of words it has
     * been told to use; falls back to assembling one from the age floor and the
     * hazards so that a category created in a hurry still warns somebody.
     */
    public function warning(): ?string
    {
        if ($this->warning_text) {
            return $this->warning_text;
        }

        $parts = [];

        if ($this->min_age_months) {
            $parts[] = 'Not suitable for children under '.AgeGrade::describeMonths((int) $this->min_age_months);
        }

        foreach ($this->hazardLabels() as $hazard) {
            $parts[] = $hazard;
        }

        return $parts === [] ? null : implode('. ', $parts).'.';
    }
}
