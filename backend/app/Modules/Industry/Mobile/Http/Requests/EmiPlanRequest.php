<?php

declare(strict_types=1);

namespace App\Modules\Industry\Mobile\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for opening a finance file.
 *
 * Two rules earn their place and both are about not lying to the cash book:
 *
 *  - An in-house file must name the invoice it is financing. Without one there is
 *    nothing for a collection to be posted against, and the money would land outside
 *    receivables — visible on this screen and nowhere in the accounts.
 *  - A finance-company file must name the financier. It is who the customer is sent
 *    to when they ring about a debit they do not recognise, and "some bank" is not an
 *    answer a counter can give.
 */
class EmiPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'exists:customers,id'],
            'financier' => ['required', Rule::in(['in_house', 'finance_company'])],
            'financier_name' => ['nullable', 'string', 'max:120'],
            'loan_account_no' => ['nullable', 'string', 'max:60'],

            'invoice_id' => ['nullable', 'exists:invoices,id'],
            'product_id' => ['nullable', 'exists:products,id'],
            'handset_id' => ['nullable', 'exists:mobile_handsets,id'],
            'imei' => ['nullable', 'string', 'max:40'],

            'asset_value' => ['required', 'numeric', 'min:0'],
            'down_payment' => ['nullable', 'numeric', 'min:0'],
            'processing_fee' => ['nullable', 'numeric', 'min:0'],
            'interest_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],

            // Three to twenty-four months covers every consumer-durable scheme in this
            // trade; the ceiling is loose enough for the odd long one and tight enough
            // to catch a mis-key.
            'tenure_months' => ['required', 'integer', 'min:1', 'max:60'],
            'first_due_date' => ['required', 'date'],

            'status' => ['nullable', Rule::in(['active', 'closed', 'defaulted', 'cancelled'])],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $asset = (float) $this->input('asset_value', 0);
            $down = (float) $this->input('down_payment', 0);

            if ($down > $asset) {
                $validator->errors()->add(
                    'down_payment',
                    'The down payment cannot be more than the value of the handset.',
                );
            }

            // Nothing left to finance is not a finance file, it is a paid sale.
            if ($asset - $down <= 0) {
                $validator->errors()->add(
                    'down_payment',
                    'Nothing is left to finance once this down payment is taken — this sale does not need an EMI file.',
                );
            }

            if ($this->input('financier') === 'in_house' && ! $this->filled('invoice_id')) {
                $validator->errors()->add(
                    'invoice_id',
                    'An in-house file has to name the invoice it is financing, or its collections have nothing to be posted against.',
                );
            }

            if ($this->input('financier') === 'finance_company' && ! $this->filled('financier_name')) {
                $validator->errors()->add(
                    'financier_name',
                    'Name the financier — it is who the customer has to be sent to.',
                );
            }
        });
    }
}
