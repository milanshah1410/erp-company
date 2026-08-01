<?php

declare(strict_types=1);

namespace App\Modules\Industry\Agriculture\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a dealer's input licence.
 *
 * Two rules carry weight. A licence number is unique *per type* rather than
 * outright, because the same running number is genuinely issued under different
 * Acts by different offices. And `valid_till` may not precede `issued_on` — a
 * transposed pair of dates would put a live licence in the expired bucket and
 * stop the shop for no reason.
 */
class AgriLicenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $licenceId = $this->route('agriLicence')?->id;

        return [
            'licence_type' => ['required', Rule::in(['seed', 'fertilizer', 'insecticide', 'other'])],
            'licence_number' => [
                'required', 'string', 'max:100',
                Rule::unique('agri_licences', 'licence_number')
                    ->where('tenant_id', app(TenantContext::class)->id())
                    ->where('licence_type', $this->input('licence_type'))
                    ->ignore($licenceId),
            ],
            'form_number' => ['nullable', 'string', 'max:50'],
            'authority' => ['nullable', 'string', 'max:255'],
            'holder_name' => ['nullable', 'string', 'max:255'],
            'premises' => ['nullable', 'string', 'max:2000'],
            'issued_on' => ['nullable', 'date'],
            // Nullable means "granted in perpetuity", not "we have not checked".
            'valid_till' => ['nullable', 'date', 'after_or_equal:issued_on'],
            'status' => ['nullable', Rule::in(['active', 'suspended', 'surrendered'])],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'licence_number.unique' => 'This licence number is already on record for that licence type.',
            'valid_till.after_or_equal' => 'The validity date cannot be before the date of issue.',
        ];
    }
}
