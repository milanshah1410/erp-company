<?php

declare(strict_types=1);

namespace App\Modules\Industry\BookStore\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A person on the cover — writer, editor, translator or illustrator.
 *
 * The register exists to answer one question, and it is the question a bookshop
 * counter is asked more than any other: *what else do you have by her?* A string
 * column on the product cannot answer it, because two spellings of one name are
 * two authors to a `LIKE` and the customer is told the shop has nothing.
 *
 * Which is also why the name is unique per tenant. The failure mode of this
 * register is not collision but duplication: the same author entered twice
 * splits their shelf, and the half that is missing is invisible — nothing looks
 * wrong, the answer is simply incomplete. A shop with two genuinely different
 * people of one name disambiguates in the name itself, exactly as a library does.
 */
class Author extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $table = 'book_authors';

    protected $fillable = [
        'name', 'sort_name', 'nationality', 'language',
        'biography', 'website', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
    ];

    /** Every credit this person holds, across all their roles. */
    public function contributions(): HasMany
    {
        return $this->hasMany(TitleContributor::class, 'book_author_id');
    }

    /**
     * How the shelf is alphabetised. Held on the row rather than derived from
     * the name, because no derivation survives real names — particles ("van
     * Gogh"), single names ("Premchand"), honorifics, and scripts with no
     * surname at all. Falls back to the name when the shop has not said
     * otherwise, which is right far more often than a guess would be.
     */
    public function shelfName(): string
    {
        return $this->sort_name ?: (string) $this->name;
    }
}
