<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Services\CustomFieldService;

/**
 * Adds a tenant's custom fields to a FormRequest.
 *
 * Wiring an entity up is three lines:
 *
 *   use ValidatesCustomFields;
 *   protected function customFieldEntity(): string { return 'product'; }
 *   // in rules():  return [ … ] + $this->customFieldRules();
 *
 * The trait overrides prepareForValidation() and attributes(). A request class
 * that needs its own version of either must call prepareCustomFieldInput() /
 * merge customFieldAttributeNames() itself — a method on the class wins over
 * one from a trait, so the trait's copy would otherwise be skipped silently.
 */
trait ValidatesCustomFields
{
    /** The CustomFieldEntities key this request writes ('product', 'customer', …). */
    abstract protected function customFieldEntity(): string;

    /**
     * Rules for the `custom_fields` bag, ready to merge into rules().
     *
     * @return array<string, list<mixed>>
     */
    protected function customFieldRules(): array
    {
        return app(CustomFieldService::class)->rules($this->customFieldEntity());
    }

    protected function prepareForValidation(): void
    {
        $this->prepareCustomFieldInput();
    }

    /**
     * Strip keys with no definition, and on create fill in the defaults the
     * client did not send.
     *
     * An update that never mentions `custom_fields` is left alone rather than
     * normalised to an empty bag: a PATCH of one price must not clear every
     * custom value on the record.
     */
    protected function prepareCustomFieldInput(): void
    {
        $isCreate = $this->isMethod('post');

        if (! $isCreate && ! $this->has('custom_fields')) {
            return;
        }

        $this->merge([
            'custom_fields' => app(CustomFieldService::class)->prepareInput(
                $this->customFieldEntity(),
                $this->input('custom_fields'),
                $isCreate,
            ),
        ]);
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return $this->customFieldAttributeNames();
    }

    /** @return array<string, string> */
    protected function customFieldAttributeNames(): array
    {
        return app(CustomFieldService::class)->attributeNames($this->customFieldEntity());
    }
}
