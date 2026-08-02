<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductWarrantyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'exists:customers,id'],
            'product_id' => ['required', 'exists:products,id'],
            'variant_id' => ['nullable', 'exists:product_variants,id'],
            'serial_no' => ['nullable', 'string', 'max:100'],
            'invoice_id' => ['nullable', 'exists:invoices,id'],
            'source' => ['nullable', Rule::in(['manufacturer', 'shop', 'extended'])],

            'start_date' => ['required', 'date'],
            /**
             * The end date is required rather than derived from coverage_months.
             * Both are accepted and both are stored, but only one of them is what
             * the customer was actually promised — an extended plan sold as
             * "to 31 March" is not "twelve months from today", and quietly
             * recomputing it would move the date a shop already printed.
             * The controller fills it in from the months when it is absent.
             */
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'coverage_months' => ['nullable', 'integer', 'min:0', 'max:600'],

            /**
             * Industry-plugin fields, added by Electronics & Appliances.
             *
             * `unit_id` names the machine the card was issued against, so cover
             * can be found by scanning rather than by matching a typed serial.
             *
             * The scope pair is what the card actually pays for. It matters
             * wherever a claim can cost labour as well as parts: "3 years" on a
             * television almost always means parts and labour in year one and the
             * panel only afterwards. A trade where a claim is simply a
             * replacement — footwear, a watch strap — leaves both true and never
             * notices they are there.
             */
            'unit_id' => ['nullable', 'exists:electronics_units,id'],
            'covers_parts' => ['boolean'],
            'covers_labour' => ['boolean'],
            'is_onsite' => ['boolean'],

            // 'expired' is deliberately not settable: it is worked out from the
            // end date on every read, so setting it by hand could only ever
            // disagree with the calendar.
            'status' => ['nullable', Rule::in(['active', 'claimed', 'void'])],
            'terms' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
