<?php

declare(strict_types=1);

namespace App\Modules\Industry\Gift\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GiftPackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $id = $this->route('pack')?->id;
        $tenantId = app(TenantContext::class)->id();

        return [
            'code' => [
                $this->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:40',
                Rule::unique('gift_packs', 'code')->where('tenant_id', $tenantId)->ignore($id),
            ],
            'name' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:160'],
            /**
             * The hamper's own product, and one pack per product: two bills of
             * contents for one SKU means a build could assemble either, and the
             * cost of a hamper would depend on which row someone opened.
             */
            'product_id' => [
                $this->isMethod('post') ? 'required' : 'sometimes',
                'exists:products,id',
                Rule::unique('gift_packs', 'product_id')->where('tenant_id', $tenantId)->ignore($id),
            ],
            'occasion_tag' => ['nullable', 'string', 'max:60'],
            'wrap_option_id' => ['nullable', 'exists:gift_wrap_options,id'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],

            /**
             * Contents are sent whole and replace what was there, which is how a
             * grid edits: a hamper is designed as a list, not amended line by line.
             * Absent means "leave the contents alone" — an empty array means
             * "empty it", and those are different intentions.
             */
            'items' => ['sometimes', 'array', 'max:60'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:9999'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'A pack with that code already exists.',
            'product_id.unique' => 'That product is already the hamper for another pack. Edit that pack instead.',
        ];
    }
}
