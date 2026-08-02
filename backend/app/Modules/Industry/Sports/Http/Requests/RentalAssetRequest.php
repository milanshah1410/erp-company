<?php

declare(strict_types=1);

namespace App\Modules\Industry\Sports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Adding a piece of kit to the hire fleet.
 *
 * The one rule with teeth is that an asset must be hireable on *something*: an
 * article with no rate on any basis cannot go on a contract, so a fleet row with
 * four empty rate columns is a row that will be picked at the counter and then
 * refused. Better to say so here.
 */
class RentalAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $id = $this->route('asset')?->id;

        return [
            'asset_code' => [
                'required', 'string', 'max:40',
                Rule::unique('sports_rental_assets', 'asset_code')
                    ->where('tenant_id', $this->user()?->tenant_id)
                    ->ignore($id),
            ],
            'name' => ['required', 'string', 'max:120'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'serial_no' => ['nullable', 'string', 'max:80'],
            'category' => ['nullable', 'string', 'max:60'],

            // Each basis independently optional — a null is "not offered on that
            // basis", which is a real and common answer.
            'rate_hourly' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'rate_daily' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'rate_weekly' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'rate_monthly' => ['nullable', 'numeric', 'min:0', 'max:9999999'],

            'deposit_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            /**
             * Floored at 100, because a "late fee" below the ordinary rate would
             * make keeping the kit an extra day cheaper than hiring it for one —
             * which is not a discount policy, it is a bug that pays customers to
             * be late.
             */
            'late_fee_percent' => ['nullable', 'integer', 'min:100', 'max:1000'],
            'requires_id_proof' => ['boolean'],
            'condition' => ['nullable', Rule::in(['new', 'good', 'fair', 'worn', 'damaged'])],
            'status' => ['nullable', Rule::in(['available', 'maintenance', 'retired'])],
            'purchase_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'acquired_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $offered = collect(['rate_hourly', 'rate_daily', 'rate_weekly', 'rate_monthly'])
                ->contains(fn (string $field) => $this->filled($field));

            if (! $offered) {
                $v->errors()->add(
                    'rate_daily',
                    'Set at least one rate. Kit with no rate on any basis cannot go on a hire contract.',
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'asset_code.unique' => 'That asset code is already on another piece of kit.',
            'late_fee_percent.min' => 'A late rate below 100% would make keeping the kit cheaper than hiring it.',
        ];
    }
}
