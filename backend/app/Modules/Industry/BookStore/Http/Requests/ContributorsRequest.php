<?php

declare(strict_types=1);

namespace App\Modules\Industry\BookStore\Http\Requests;

use App\Modules\Industry\BookStore\Models\TitleContributor;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The whole set of people on one title, sent at once.
 *
 * A replace and not a patch, because that is how the screen behaves: the editor
 * shows every credit on the book and the shop presses Save. Sending the full set
 * means removing a wrongly-added translator is the same operation as adding one,
 * and there is no path where a half-applied change leaves a title crediting
 * somebody it should not.
 */
class ContributorsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        return [
            // `present` rather than `required`: an empty array is the legitimate
            // way to say "nobody is credited on this yet", which is the state
            // every imported title starts in.
            'contributors' => ['present', 'array', 'max:20'],
            'contributors.*.book_author_id' => ['required', 'exists:book_authors,id'],
            'contributors.*.role' => ['required', Rule::in(array_keys(TitleContributor::ROLES))],
            'contributors.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * One person may appear twice on a title in two roles — writing and
     * illustrating a picture book is the common case — but not twice in the same
     * one. The database enforces it too; catching it here is what turns a
     * constraint violation into a sentence the shop can act on.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $seen = [];

            foreach ((array) $this->input('contributors', []) as $index => $row) {
                $key = ($row['book_author_id'] ?? '').':'.($row['role'] ?? '');

                if (isset($seen[$key])) {
                    $validator->errors()->add(
                        "contributors.{$index}.book_author_id",
                        'This person is already credited in that role on this title.',
                    );
                }

                $seen[$key] = true;
            }
        });
    }
}
