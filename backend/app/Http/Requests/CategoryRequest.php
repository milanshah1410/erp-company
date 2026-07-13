<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $categoryId = $this->route('category')?->id;

        return [
            'parent_id' => [
                'nullable',
                'exists:categories,id',
                // Guard the hierarchy: a category cannot be parented to
                // itself or to any of its own descendants.
                function (string $attribute, $value, \Closure $fail) use ($categoryId) {
                    if (! $categoryId || ! $value) {
                        return;
                    }
                    if ((int) $value === (int) $categoryId) {
                        $fail('A category cannot be its own parent.');

                        return;
                    }
                    if (in_array((int) $value, $this->descendantIds((int) $categoryId), true)) {
                        $fail('A category cannot be moved under one of its own sub-categories.');
                    }
                },
            ],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('categories', 'slug')->ignore($categoryId)],
            'description' => ['nullable', 'string'],
            // Attribute definitions products in this category can fill in.
            'attributes' => ['nullable', 'array'],
            'attributes.*.name' => ['required', 'string', 'max:100'],
            'attributes.*.type' => ['required', Rule::in(['text', 'number', 'select'])],
            'attributes.*.options' => ['nullable', 'array', 'required_if:attributes.*.type,select'],
            'attributes.*.options.*' => ['string', 'max:100'],
            'attributes.*.required' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    /** All descendant category ids of the given category (BFS). */
    private function descendantIds(int $categoryId): array
    {
        $all = [];
        $frontier = [$categoryId];

        while ($frontier !== []) {
            $children = DB::table('categories')->whereIn('parent_id', $frontier)->pluck('id')->all();
            $children = array_diff($children, $all); // safety against pre-existing cycles
            $all = array_merge($all, $children);
            $frontier = $children;
        }

        return array_map('intval', $all);
    }
}
