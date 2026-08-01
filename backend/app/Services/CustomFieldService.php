<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CustomFieldDefinition;
use App\Support\CustomFieldEntities;
use App\Support\EnabledModules;
use App\Support\TenantCache;
use App\Support\TenantContext;
use Illuminate\Validation\Rule;

/**
 * Turns custom-field definitions into the four things the rest of the app needs
 * from them: a form schema, a set of validation rules, sanitised input and
 * typed values.
 *
 * Everything reads through here rather than querying CustomFieldDefinition
 * directly, for two reasons. The definitions are read on every form and list
 * load, so they are cached per tenant (bumped on write by the model). And a
 * field can be tagged with a module — filtering that out in one place is what
 * stops a switched-off module's fields from being required by an endpoint while
 * being invisible on the form that should have filled them.
 */
class CustomFieldService
{
    /** How long a tenant's definitions are cached (seconds). */
    private const TTL = 600;

    /** Cache group; bumped by CustomFieldDefinition::cacheGroup(). */
    private const GROUP = 'custom_fields';

    /**
     * Active definitions for an entity, narrowed to the tenant's enabled
     * modules and ordered the way the form renders them.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(string $entity): array
    {
        if (! CustomFieldEntities::exists($entity)) {
            return [];
        }

        // The module filter is applied on read rather than baked into the cache:
        // switching a module on must take effect immediately, not in ten minutes.
        $enabled = EnabledModules::forTenant(app(TenantContext::class)->id());

        return array_values(array_filter(
            $this->cached($entity),
            static fn (array $def) => $def['module'] === null
                || $enabled === null
                || in_array($def['module'], $enabled, true),
        ));
    }

    /**
     * Validation rules for a request body carrying `custom_fields`.
     *
     * Returns rules keyed `custom_fields.<key>`, ready to merge into a
     * FormRequest's own rules.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(string $entity): array
    {
        $rules = ['custom_fields' => ['sometimes', 'nullable', 'array']];

        foreach ($this->definitions($entity) as $def) {
            $path = 'custom_fields.'.$def['key'];
            $rules[$path] = $this->rulesFor($def);

            if ($def['type'] === 'multiselect') {
                $rules[$path.'.*'] = $def['choices'] === []
                    ? ['string']
                    : [Rule::in($def['choices'])];
            }
        }

        return $rules;
    }

    /**
     * Human labels for validation messages, so a failure reads "The ISBN field
     * is required" rather than naming the JSON path.
     *
     * @return array<string, string>
     */
    public function attributeNames(string $entity): array
    {
        $names = [];
        foreach ($this->definitions($entity) as $def) {
            $names['custom_fields.'.$def['key']] = $def['label'];
        }

        return $names;
    }

    /**
     * Clean untrusted input before validation: drop keys with no definition
     * (a stale form build, or someone writing straight to the API) and, on
     * create, fill in defaults for keys the client did not send.
     *
     * @param  mixed  $input  whatever arrived as `custom_fields`
     * @return array<string, mixed>
     */
    public function prepareInput(string $entity, mixed $input, bool $applyDefaults = false): array
    {
        $input = is_array($input) ? $input : [];
        $out = [];

        foreach ($this->definitions($entity) as $def) {
            $key = $def['key'];

            if (array_key_exists($key, $input)) {
                $out[$key] = $input[$key];

                continue;
            }

            if ($applyDefaults && $def['default'] !== null) {
                $out[$key] = $def['default'];
            }
        }

        return $out;
    }

    /**
     * Coerce values to their declared types on the way into storage, so a JSON
     * blob holds a real number and a real boolean rather than "12" and "on".
     *
     * Keys with no definition are passed through untouched: a deleted or
     * module-hidden definition must not silently wipe values a record already
     * holds the next time it is saved for an unrelated reason.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function cast(string $entity, array $values): array
    {
        $byKey = [];
        foreach ($this->cached($entity) as $def) {
            $byKey[$def['key']] = $def;
        }

        $out = [];
        foreach ($values as $key => $value) {
            $def = $byKey[$key] ?? null;
            $out[$key] = $def === null ? $value : $this->castValue($def['type'], $value);
        }

        return $out;
    }

    /**
     * Keys that take part in an entity's free-text search.
     *
     * @return list<string>
     */
    public function searchableKeys(string $entity): array
    {
        return array_values(array_map(
            static fn (array $def) => $def['key'],
            array_filter($this->definitions($entity), static fn (array $def) => $def['searchable']),
        ));
    }

    /** Drop every cached definition set for the current tenant. */
    public static function flush(): void
    {
        TenantCache::bump(self::GROUP);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Every active definition for the entity, cached, before the module filter.
     *
     * Cached as plain arrays rather than Eloquent models: the database cache
     * driver serialises what it is given, and rehydrating models on every read
     * costs more than the query it replaces.
     *
     * @return list<array<string, mixed>>
     */
    private function cached(string $entity): array
    {
        return TenantCache::rememberVersioned(self::GROUP, $entity, self::TTL, function () use ($entity): array {
            return CustomFieldDefinition::query()
                ->forEntity($entity)
                ->active()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (CustomFieldDefinition $def) => $this->toSchema($def))
                ->all();
        });
    }

    /**
     * The wire/cache shape of one definition — also exactly what the frontend
     * form renderer consumes, so the schema endpoint has nothing to translate.
     *
     * @return array<string, mixed>
     */
    private function toSchema(CustomFieldDefinition $def): array
    {
        $choices = $def->choiceValues();

        return [
            'id' => $def->id,
            'key' => $def->key,
            'label' => $def->label,
            'help_text' => $def->help_text,
            'type' => $def->type,
            'options' => $this->normaliseOptions($def->options ?? []),
            'choices' => $choices,
            'default' => $def->default_value === null || $def->default_value === ''
                ? null
                : $this->castValue($def->type, $def->default_value),
            'required' => $def->is_required,
            'min' => $def->min_value === null ? null : (float) $def->min_value,
            'max' => $def->max_value === null ? null : (float) $def->max_value,
            'pattern' => $def->pattern,
            'show_in_list' => $def->show_in_list,
            'searchable' => $def->is_searchable,
            'module' => $def->module,
        ];
    }

    /**
     * Accept options as either ["S","M"] or [{value,label}] and always emit the
     * second shape, so the renderer has one thing to loop over.
     *
     * @param  array<int, mixed>  $options
     * @return list<array{value: string, label: string}>
     */
    private function normaliseOptions(array $options): array
    {
        $out = [];
        foreach ($options as $option) {
            if (is_array($option)) {
                $value = (string) ($option['value'] ?? '');
                $label = (string) ($option['label'] ?? $value);
            } else {
                $value = (string) $option;
                $label = $value;
            }

            if ($value !== '') {
                $out[] = ['value' => $value, 'label' => $label];
            }
        }

        return $out;
    }

    /**
     * Rules for one field. min/max mean length on text types and magnitude on
     * numeric ones — a field is never both, so one pair of columns covers both
     * readings.
     *
     * @param  array<string, mixed>  $def
     * @return list<mixed>
     */
    private function rulesFor(array $def): array
    {
        // A required boolean is a checkbox that must be ticked, which is almost
        // never what an admin means; `present` lets false through while still
        // insisting the key is sent.
        $rules = [$def['required'] ? ($def['type'] === 'boolean' ? 'present' : 'required') : 'nullable'];

        $min = $def['min'];
        $max = $def['max'];

        switch ($def['type']) {
            case 'text':
                $rules[] = 'string';
                $rules[] = 'max:'.(int) ($max ?? 255);
                if ($min !== null) {
                    $rules[] = 'min:'.(int) $min;
                }
                if ($expr = $this->patternExpression($def['pattern'])) {
                    $rules[] = 'regex:'.$expr;
                }
                break;

            case 'textarea':
                $rules[] = 'string';
                $rules[] = 'max:'.(int) ($max ?? 5000);
                break;

            case 'number':
                $rules[] = 'integer';
                if ($min !== null) {
                    $rules[] = 'min:'.(int) $min;
                }
                if ($max !== null) {
                    $rules[] = 'max:'.(int) $max;
                }
                break;

            case 'decimal':
                $rules[] = 'numeric';
                if ($min !== null) {
                    $rules[] = 'min:'.$min;
                }
                if ($max !== null) {
                    $rules[] = 'max:'.$max;
                }
                break;

            case 'date':
                $rules[] = 'date';
                break;

            case 'boolean':
                $rules[] = 'boolean';
                break;

            case 'email':
                $rules[] = 'email';
                $rules[] = 'max:255';
                break;

            case 'url':
                $rules[] = 'url';
                $rules[] = 'max:255';
                break;

            case 'select':
                if ($def['choices'] !== []) {
                    $rules[] = Rule::in($def['choices']);
                } else {
                    $rules[] = 'string';
                }
                break;

            case 'multiselect':
                $rules[] = 'array';
                break;
        }

        return $rules;
    }

    /**
     * Wrap an admin-supplied bare regex into a delimited, anchored expression.
     *
     * Returns null when the result does not compile, so a typo in the field
     * builder degrades to "no pattern check" rather than throwing on every save
     * of the entity. {@see CustomFieldDefinitionRequest} rejects it at the point
     * it is entered; this is the runtime backstop.
     */
    public function patternExpression(?string $pattern): ?string
    {
        if ($pattern === null || $pattern === '') {
            return null;
        }

        $expr = '#^(?:'.$pattern.')$#u';

        return @preg_match($expr, '') === false ? null : $expr;
    }

    private function castValue(string $type, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            'number' => is_numeric($value) ? (int) $value : null,
            'decimal' => is_numeric($value) ? (float) $value : null,
            'multiselect' => array_values(array_map(
                static fn ($v) => (string) $v,
                is_array($value) ? $value : array_filter([$value]),
            )),
            // Empty strings become null so `required` and "is this filled in?"
            // agree, and so an untouched field does not litter the JSON.
            default => trim((string) $value) === '' ? null : (string) $value,
        };
    }
}
