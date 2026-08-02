<?php

declare(strict_types=1);

namespace App\Modules\Industry\Cosmetics\Http\Requests;

use App\Modules\Industry\Cosmetics\Models\CosmeticShade;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShadeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $id = $this->route('shade')?->id;
        $tenantId = app(TenantContext::class)->id();
        $brandId = $this->input('brand_id');

        return [
            /**
             * Unique per brand, not per shop. Two houses both number a foundation
             * "015" and a shop carrying both must be able to hold both.
             *
             * The rule is written here as well as in the schema because the DB's
             * unique index cannot enforce the brandless case: MySQL treats NULLs
             * as distinct, so (tenant, NULL, '015') can repeat as often as it
             * likes. `whereNull` closes exactly that gap.
             */
            'code' => [
                'required', 'string', 'max:40',
                Rule::unique('cosmetic_shades', 'code')
                    ->where('tenant_id', $tenantId)
                    ->where(fn ($q) => $brandId ? $q->where('brand_id', $brandId) : $q->whereNull('brand_id'))
                    ->ignore($id),
            ],
            'name' => ['required', 'string', 'max:80'],
            'brand_id' => ['nullable', 'exists:brands,id'],
            'family' => ['nullable', 'string', 'max:40'],
            'finish' => ['required', Rule::in(CosmeticShade::FINISHES)],
            'undertone' => ['required', Rule::in(CosmeticShade::UNDERTONES)],
            /**
             * 1–20, fair to deep. Only base make-up carries it, hence nullable —
             * and the ceiling is not fussiness: a depth typed as the shade number
             * ("42" from NC42) would sort the whole card into nonsense, and this
             * is the only place that can be caught.
             */
            'depth_level' => ['nullable', 'integer', 'min:1', 'max:20'],
            'hex' => ['nullable', 'string', 'regex:/^#?[0-9A-Fa-f]{6}$/'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // A swatch typed without the hash is the common case, and rejecting it
        // teaches nobody anything.
        if (is_string($hex = $this->input('hex')) && $hex !== '' && ! str_starts_with($hex, '#')) {
            $this->merge(['hex' => '#'.$hex]);
        }
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'This brand already has a shade with that code.',
            'hex.regex' => 'A swatch is six hex digits, such as #C8556A.',
            'depth_level.max' => 'Depth runs 1 (fairest) to 20 (deepest) — it is not the shade number.',
        ];
    }
}
