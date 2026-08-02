<?php

declare(strict_types=1);

namespace App\Modules\Industry\BookStore\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's credit on one title.
 *
 * The role is the reason this is a table and not a column. A translated novel
 * has an author and a translator and the translator's name is what sells it in
 * Gujarati; an anthology has an editor and no author at all; a picture book has
 * an illustrator whose name is bigger than the writer's. A customer asking for
 * "the one Mahadevbhai did" may mean any of them, and a single `author` string
 * would have thrown away the only field that tells them apart.
 */
class TitleContributor extends Model
{
    use BelongsToTenant;

    protected $table = 'book_title_contributors';

    protected $fillable = [
        'product_id', 'book_author_id', 'role', 'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'role' => 'author',
        'sort_order' => 0,
    ];

    /**
     * The credits a book shop actually prints, and the words it uses for them.
     *
     * @var array<string, string>
     */
    public const ROLES = [
        'author' => 'Author',
        'co_author' => 'Co-author',
        'editor' => 'Editor',
        'translator' => 'Translator',
        'illustrator' => 'Illustrator',
        'foreword' => 'Foreword by',
        'compiler' => 'Compiler',
        'narrator' => 'Narrator',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'book_author_id');
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? ucfirst((string) $this->role);
    }
}
