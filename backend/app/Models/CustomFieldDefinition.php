<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\InvalidatesTenantCache;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One user-defined field on one entity.
 *
 * Definitions are read on every form and list load and written only when an
 * admin edits the field builder, so they are cached per tenant and the cache is
 * bumped on write (InvalidatesTenantCache). {@see App\Services\CustomFieldService}
 * is the only thing that should read them at runtime — it applies the module
 * filter and turns them into validation rules.
 */
class CustomFieldDefinition extends Model
{
    use BelongsToTenant, InvalidatesTenantCache, LogsAuditActivity;

    /** Types whose value is a number rather than a string. */
    public const NUMERIC_TYPES = ['number', 'decimal'];

    /** Types that carry a list of choices. */
    public const CHOICE_TYPES = ['select', 'multiselect'];

    /** Types where min/max mean string length rather than magnitude. */
    public const TEXT_TYPES = ['text', 'textarea'];

    /** Every supported field type — the enum in the migration, in render order. */
    public const TYPES = [
        'text', 'textarea', 'number', 'decimal', 'date',
        'select', 'multiselect', 'boolean', 'email', 'url',
    ];

    protected $fillable = [
        'entity', 'key', 'label', 'help_text', 'type', 'options', 'default_value',
        'is_required', 'min_value', 'max_value', 'pattern',
        'show_in_list', 'is_searchable', 'module', 'sort_order', 'is_active',
        'created_by',
    ];

    protected $casts = [
        'options' => 'array',
        'is_required' => 'boolean',
        'show_in_list' => 'boolean',
        'is_searchable' => 'boolean',
        'is_active' => 'boolean',
        'min_value' => 'decimal:4',
        'max_value' => 'decimal:4',
        'sort_order' => 'integer',
    ];

    /**
     * Shared with every entity's definitions rather than one group per entity:
     * the whole table is a handful of rows, and a single group means one bump
     * can never leave one entity stale while another is fresh.
     */
    public function cacheGroup(): string
    {
        return 'custom_fields';
    }

    public function scopeForEntity(Builder $query, string $entity): Builder
    {
        return $query->where('entity', $entity);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Choice values, flattened for an `in:` rule. Empty for non-choice types. */
    public function choiceValues(): array
    {
        if (! in_array($this->type, self::CHOICE_TYPES, true)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($o) => is_array($o) ? ($o['value'] ?? null) : $o,
            $this->options ?? [],
        ), static fn ($v) => $v !== null && $v !== ''));
    }
}
