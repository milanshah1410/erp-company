<?php

declare(strict_types=1);

namespace App\Modules\Industry\Gift\Http\Requests;

use App\Modules\Industry\Gift\Models\GiftWrapOption;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GiftWrapOptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $id = $this->route('option')?->id;
        $tenantId = app(TenantContext::class)->id();

        return [
            /**
             * Scoped by hand — the model's global tenant scope does not reach a
             * validation rule. The code is what the counter picks the wrapping by,
             * so two options sharing one is a genuine ambiguity.
             */
            'code' => [
                $this->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:40',
                Rule::unique('gift_wrap_options', 'code')->where('tenant_id', $tenantId)->ignore($id),
            ],
            'name' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:120'],
            'kind' => ['nullable', Rule::in(GiftWrapOption::KINDS)],
            'charge' => ['nullable', 'numeric', 'min:0'],
            /**
             * The service product the charge is rung up as. Required to be a
             * service rather than merely a product: billing wrapping as a stocked
             * item would drive that item's stock negative every time someone paid
             * for a bow.
             */
            'product_id' => ['nullable', 'exists:products,id'],
            'consumes_product_id' => ['nullable', 'exists:products,id'],
            'consume_quantity' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            // Four hours. Beyond that the desk is not quoting a wait, it is taking
            // an order for tomorrow.
            'turnaround_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'A wrapping option with that code already exists.',
        ];
    }
}
