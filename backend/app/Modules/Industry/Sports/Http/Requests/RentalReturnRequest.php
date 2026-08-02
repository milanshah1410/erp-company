<?php

declare(strict_types=1);

namespace App\Modules\Industry\Sports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Taking kit back — some of it or all of it.
 *
 * `returned_at` is accepted rather than assumed, because a hire desk on a Sunday
 * evening writes the returns up on Monday morning and charging everyone an extra
 * day for the shop's own paperwork is indefensible. It cannot be in the future,
 * for the obvious reason: a return time later than now is either a typo or an
 * attempt to charge for time that has not happened.
 */
class RentalReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        return [
            'returned_at' => ['nullable', 'date', 'before_or_equal:now'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer'],
            'items.*.condition_in' => ['nullable', Rule::in(['new', 'good', 'fair', 'worn', 'damaged', 'lost'])],
            'items.*.damage_charge' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'items.*.damage_note' => ['nullable', 'string', 'max:255'],
            /**
             * Whether it goes to the bench. Separate from the condition on
             * purpose: a scuffed bat is 'damaged' and goes straight back on the
             * rack, a bent wheel is 'damaged' and does not, and only the person
             * holding it knows which.
             */
            'items.*.send_to_bench' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        /**
         * A damage charge with nothing written against it is the line a customer
         * disputes and the shop cannot defend three weeks later. Cheap to insist
         * on now, impossible to reconstruct afterwards.
         */
        $validator->after(function (Validator $v) {
            foreach ((array) $this->input('items', []) as $index => $item) {
                $charge = (float) ($item['damage_charge'] ?? 0);

                if ($charge > 0 && trim((string) ($item['damage_note'] ?? '')) === '') {
                    $v->errors()->add(
                        "items.{$index}.damage_note",
                        'Say what the damage was. A charge with no note is the one that gets disputed.',
                    );
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Choose what is coming back.',
            'returned_at.before_or_equal' => 'A return cannot be recorded for a time that has not happened yet.',
        ];
    }
}
