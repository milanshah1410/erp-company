<?php

declare(strict_types=1);

namespace App\Modules\Industry\Mobile\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for selling a handset insurance policy.
 *
 * Three rules, and each of them exists because of a promise that would otherwise be
 * made at the counter and refused at claim time:
 *
 *  - The policy must name a handset somebody can look up. Either the register row or
 *    the IMEI off the box.
 *  - It must name at least one peril. An insurance with nothing covered covers
 *    nothing, and the row looks perfectly complete.
 *  - The premium must be stated. An omitted one is nearly always a skipped field
 *    rather than a giveaway, so 0 has to be entered deliberately.
 */
class InsurancePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // The underwriter, not the shop. Required, because it is who the customer
            // has to be sent to when they ring about a cracked screen.
            'provider' => ['required', 'string', 'max:120'],
            'provider_policy_no' => ['nullable', 'string', 'max:60'],

            'customer_id' => ['nullable', 'exists:customers,id'],
            'handset_id' => ['nullable', 'exists:mobile_handsets,id'],
            'product_id' => ['nullable', 'exists:products,id'],
            // Kept loose on length because it arrives with spaces and dashes in it;
            // the model strips it to digits on save.
            'imei' => ['nullable', 'string', 'max:40'],
            'invoice_id' => ['nullable', 'exists:invoices,id'],

            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'coverage_months' => ['nullable', 'integer', 'min:1', 'max:60'],

            'premium' => ['nullable', 'numeric', 'min:0'],
            'sum_insured' => ['nullable', 'numeric', 'min:0'],
            'deductible' => ['nullable', 'numeric', 'min:0'],

            'covers_accidental_damage' => ['nullable', 'boolean'],
            'covers_liquid_damage' => ['nullable', 'boolean'],
            'covers_theft' => ['nullable', 'boolean'],
            // Null means unlimited. Rare in this trade but real on a top-end plan.
            'claim_limit' => ['nullable', 'integer', 'min:1', 'max:20'],

            'status' => ['nullable', Rule::in(['active', 'expired', 'claimed', 'void'])],
            'terms' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->filled('handset_id') && ! $this->filled('imei')) {
                $validator->errors()->add(
                    'imei',
                    'A policy has to name the handset it covers — pick the registered handset, or key the IMEI off the box.',
                );
            }

            if (! $this->boolean('covers_accidental_damage')
                && ! $this->boolean('covers_liquid_damage')
                && ! $this->boolean('covers_theft')) {
                $validator->errors()->add(
                    'covers_accidental_damage',
                    'Say what the policy covers. A policy with no peril named covers nothing, and the customer will be told otherwise.',
                );
            }

            /**
             * An *omitted* premium is refused while an explicit 0 passes. The
             * distinction only works because this reads the raw input rather than a
             * cast value — 0 and "not filled" are the same number otherwise.
             */
            if ($this->input('premium') === null || $this->input('premium') === '') {
                $validator->errors()->add(
                    'premium',
                    'What did the customer pay for this policy? Enter 0 if it was thrown in with the handset.',
                );
            }

            // Cover worth less than the excess pays out nothing on any claim.
            if ($this->filled('sum_insured') && $this->filled('deductible')
                && (float) $this->input('deductible') >= (float) $this->input('sum_insured')) {
                $validator->errors()->add(
                    'deductible',
                    'The excess is as much as the cover, so a claim would pay out nothing.',
                );
            }
        });
    }
}
