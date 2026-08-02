<?php

declare(strict_types=1);

namespace App\Modules\Industry\Stationery\Http\Requests;

use App\Modules\Industry\Stationery\Models\ClassKit;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ClassKitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    /**
     * Blank out the two nullable identity fields.
     *
     * The form sends `''` for an unset stream and the seeders store `null`, and
     * the duplicate check below compares on that column — so without this, "Class
     * 5" typed on the screen and "Class 5" seeded are different lists as far as
     * the check is concerned, and a school ends up with two of them.
     */
    protected function prepareForValidation(): void
    {
        foreach (['stream', 'title'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'institution_id' => ['nullable', 'exists:stationery_institutions,id'],
            'academic_year' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'class_label' => ['required', 'string', 'max:40'],
            'stream' => ['nullable', 'string', 'max:40'],
            'title' => ['nullable', 'string', 'max:140'],

            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_mandatory_set' => ['nullable', 'boolean'],
            'expected_students' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'notes' => ['nullable', 'string'],

            /**
             * Lines are optional on create so a school's list can be opened and
             * filled in over an afternoon — which is how they actually arrive,
             * one photocopied sheet at a time. Publishing is what refuses an
             * empty one.
             */
            'items' => ['nullable', 'array'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.is_optional' => ['nullable', 'boolean'],
            'items.*.remark' => ['nullable', 'string', 'max:160'],
            'items.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }

    /**
     * One list per class per session per school.
     *
     * Checked here rather than with a unique index because two of the four
     * columns are nullable and MySQL waves repeated NULLs through — a shop's
     * generic lists would be constrained and a school's would not, which is worse
     * than neither. Here it can also say something a constraint violation cannot.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $duplicate = ClassKit::query()
                ->where('academic_year', $this->input('academic_year'))
                ->where('class_label', $this->input('class_label'))
                ->where('stream', $this->input('stream'))
                ->where('institution_id', $this->input('institution_id'))
                ->when($this->route('kit'), fn ($q, $kit) => $q->whereKeyNot($kit->id))
                ->exists();

            if ($duplicate) {
                $validator->errors()->add(
                    'class_label',
                    'This class already has a list for that session. Open it instead of starting a second one.',
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'academic_year.regex' => 'Write the session as 2026-27.',
        ];
    }
}
