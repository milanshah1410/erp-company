<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WarrantyClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $claim = $this->route('claim');

        return [
            // Fixed once registered: moving a claim to a different card would
            // rewrite whose cover paid for it.
            'warranty_id' => [$claim ? 'prohibited' : 'required', 'exists:product_warranties,id'],
            'reported_on' => ['required', 'date'],
            'fault' => ['required', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in(['open', 'assessed', 'approved', 'rejected', 'resolved'])],
            'resolution' => ['nullable', Rule::in(['repair', 'replace', 'refund', 'rejected'])],
            'resolution_note' => ['nullable', 'string', 'max:2000'],
            'resolved_on' => ['nullable', 'date'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'settlement_invoice_id' => ['nullable', 'exists:invoices,id'],
            'handled_by' => ['nullable', 'exists:users,id'],
        ];
    }
}
