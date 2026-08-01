<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Services\CustomFieldService;
use App\Support\CustomFieldEntities;
use Illuminate\Database\Eloquent\Builder;

/**
 * Gives a model a bag of tenant-defined fields, stored in its own
 * `custom_fields` JSON column.
 *
 * The model does not have to declare the column fillable or cast it — the trait
 * merges both in, so adding custom fields to an entity is one `use` statement
 * plus a row in CustomFieldEntities::CATALOGUE.
 *
 * Values are coerced to their declared types on write (see the mutator below),
 * which means a value that reaches the column through an importer, a seeder or
 * a direct ->update() is typed the same as one that came through a form.
 *
 * @see App\Support\CustomFieldEntities
 * @see App\Services\CustomFieldService
 *
 * The column is declared here rather than on each host model because the trait
 * is what merges its cast in — without this, static analysis cannot see an
 * attribute that only exists once initializeHasCustomFields() has run.
 *
 * @property array<string, mixed>|null $custom_fields
 */
trait HasCustomFields
{
    public function initializeHasCustomFields(): void
    {
        $this->mergeFillable(['custom_fields']);
        $this->mergeCasts(['custom_fields' => 'array']);
    }

    /** This model's CustomFieldEntities key ('product', 'customer', …). */
    public static function customFieldEntity(): ?string
    {
        return CustomFieldEntities::forModel(static::class);
    }

    /**
     * Coerce on the way in. Keys with no definition pass through untouched, so
     * saving a record for an unrelated reason never wipes values whose
     * definition was deleted or whose module is currently switched off.
     */
    public function setCustomFieldsAttribute(mixed $value): void
    {
        $entity = static::customFieldEntity();
        $values = is_array($value) ? $value : [];

        if ($entity !== null) {
            $values = app(CustomFieldService::class)->cast($entity, $values);
        }

        // A tenant that has defined no fields keeps a null column rather than a
        // row of `[]` — most tenants never define one, and json_encode of an
        // empty PHP array would write an array where every other row holds an
        // object.
        $this->attributes['custom_fields'] = $values === []
            ? null
            : json_encode($values, JSON_UNESCAPED_UNICODE);
    }

    /** One custom-field value, or $default when it was never filled in. */
    public function cf(string $key, mixed $default = null): mixed
    {
        return data_get($this->custom_fields ?? [], $key, $default) ?? $default;
    }

    /** Set one value without disturbing the rest of the bag. */
    public function setCf(string $key, mixed $value): static
    {
        $this->custom_fields = array_merge($this->custom_fields ?? [], [$key => $value]);

        return $this;
    }

    /** Exact-match filter on one custom field. */
    public function scopeWhereCustomField(Builder $query, string $key, mixed $value): Builder
    {
        return $query->where('custom_fields->'.$key, $value);
    }

    /**
     * Free-text search across the fields an admin marked searchable.
     *
     * A no-op when nothing is marked searchable, so a caller can always chain
     * it into an existing search group without checking first.
     */
    public function scopeSearchCustomFields(Builder $query, string $term): Builder
    {
        $entity = static::customFieldEntity();
        if ($entity === null || $term === '') {
            return $query;
        }

        $keys = app(CustomFieldService::class)->searchableKeys($entity);
        if ($keys === []) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($keys, $term) {
            foreach ($keys as $key) {
                $q->orWhere('custom_fields->'.$key, 'like', '%'.$term.'%');
            }
        });
    }
}
