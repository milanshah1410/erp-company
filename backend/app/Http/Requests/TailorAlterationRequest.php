<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TailorAlterationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        return [
            'tailor_work_id' => ['nullable', 'integer', 'exists:tailor_works,id'],
            'tailor_id' => ['nullable', 'integer', 'exists:tailors,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:255'],
            'status' => ['nullable', 'in:pending,in_progress,done'],
            'charge' => ['nullable', 'numeric', 'min:0'],
            'reported_date' => ['nullable', 'date'],
            'completed_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            /**
             * The retail half. All nullable, because the workroom case that this
             * document was originally built for has none of them: a piece coming
             * back off a production work order has no customer standing behind
             * it and no bill it was sold on.
             */
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'invoice_id' => ['nullable', 'integer', 'exists:invoices,id'],
            'variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            // What the shop promised. Distinct from completed_date, which is
            // when it was actually finished — the gap between the two is the only
            // measure of whether the shop keeps its word.
            'promised_date' => ['nullable', 'date'],
        ];
    }
}
