<?php

declare(strict_types=1);

namespace App\Modules\Industry\Gift\Http\Requests;

use App\Modules\Industry\Gift\Models\GiftOccasion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GiftOccasionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        return [
            'customer_id' => [
                $this->isMethod('post') ? 'required' : 'sometimes',
                'exists:customers,id',
            ],
            /**
             * Blank means the customer themself, which is why it is nullable
             * rather than required — but it is the field that makes this register
             * worth having, so the screen asks for it first.
             */
            'person_name' => ['nullable', 'string', 'max:160'],
            'relation' => ['nullable', 'string', 'max:60'],
            'occasion' => ['nullable', Rule::in(GiftOccasion::OCCASIONS)],
            'title' => ['nullable', 'string', 'max:120'],
            /**
             * A past date is normal and must stay allowed: a birthday is the year
             * someone was born, and an anniversary the year of the wedding. What
             * recurs is derived from it.
             */
            'occasion_date' => [$this->isMethod('post') ? 'required' : 'sometimes', 'date'],
            'is_recurring' => ['nullable', 'boolean'],
            // Up to three months. Enough for a shop that orders hampers in, and
            // short enough that a reminder still means something.
            'remind_days_before' => ['nullable', 'integer', 'min:0', 'max:90'],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'preferences' => ['nullable', 'string'],
            'last_gift' => ['nullable', 'string', 'max:255'],
            'last_gifted_on' => ['nullable', 'date'],
            'notify_customer' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        /**
         * `source` and `last_reminded_on` are not accepted from the client.
         * The first records where a row came from and the second what was
         * actually sent; letting a form set either would let a shop mark
         * itself as having reminded a customer it never contacted.
         */
        ];
    }
}
