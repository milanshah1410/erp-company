<?php

declare(strict_types=1);

namespace App\Modules\Industry\BookStore\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A language the shop stocks in.
 *
 * Named `BookLanguage` rather than `Language` on purpose: the application
 * already has a notion of language — the tenant's own interface locale, en/hi/gu
 * — and it is a completely different thing from the language a book is printed
 * in. A shop whose staff work in Gujarati sells plenty of English titles.
 *
 * A register and not an enum because it is how the shelves are laid out, and
 * because the list is local. In this market a shop stocks Gujarati, Hindi,
 * English, Marathi and Sanskrit side by side, and the ones nobody's dropdown has
 * — Kutchi, Sindhi — are exactly the ones a shop that stocks them cares about.
 */
class BookLanguage extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $table = 'book_languages';

    protected $fillable = [
        'code', 'label', 'native_name', 'script', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Titles printed in this language.
     *
     * Declared here rather than on Product so the dependency points plugin →
     * core; the reverse relation is attached at boot by BookStorePlugin.
     */
    public function titles(): HasMany
    {
        return $this->hasMany(Product::class, 'book_language_id');
    }

    /**
     * What the shelf card says. The native name where the shop gave one — a
     * Gujarati section is signed "ગુજરાતી", not "Gujarati", and the customer
     * looking for it is reading the sign.
     */
    public function display(): string
    {
        return $this->native_name ?: (string) $this->label;
    }
}
