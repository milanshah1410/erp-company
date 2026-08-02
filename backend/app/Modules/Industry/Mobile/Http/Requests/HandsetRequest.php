<?php

declare(strict_types=1);

namespace App\Modules\Industry\Mobile\Http\Requests;

use App\Modules\Industry\Mobile\Models\Handset;
use App\Modules\Industry\Mobile\Support\Imei;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for registering a handset.
 *
 * The one hard rule is that a row must be findable. A handset with no IMEI and no
 * serial is a record nobody can look up when the customer comes back with it, which
 * is the only moment this register is ever read.
 *
 * A bad Luhn check digit is deliberately *not* an error. Refurbished stock, grey
 * imports and older feature phones turn up with numbers that do not check out, and
 * a register that refuses the handset in front of the counter stops being used. The
 * controller returns the check result so the screen can warn instead.
 */
class HandsetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('handset')?->id;

        return [
            'customer_id' => ['nullable', 'exists:customers,id'],
            'product_id' => ['nullable', 'exists:products,id'],
            'brand' => ['nullable', 'string', 'max:60'],
            'model_name' => ['nullable', 'string', 'max:120'],
            'storage' => ['nullable', 'string', 'max:20'],
            'colour' => ['nullable', 'string', 'max:40'],

            /**
             * Length is generous because the field arrives with spaces and dashes in
             * it — the model strips it to digits on save. Uniqueness is checked
             * against the normalised value in withValidator below, since a rule here
             * would compare the punctuated form against stored digits and pass a
             * duplicate through.
             */
            'imei_1' => ['nullable', 'string', 'max:40'],
            'imei_2' => ['nullable', 'string', 'max:40'],
            'serial_no' => [
                'nullable', 'string', 'max:100',
                Rule::unique('mobile_handsets', 'serial_no')->ignore($id),
            ],

            'status' => ['nullable', Rule::in(['in_stock', 'sold', 'returned', 'scrapped'])],
            'is_third_party' => ['nullable', 'boolean'],
            'sale_invoice_id' => ['nullable', 'exists:invoices,id'],
            'sold_at' => ['nullable', 'date'],
            'warranty_months' => ['nullable', 'integer', 'min:0', 'max:120'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->filled('imei_1') && ! $this->filled('serial_no')) {
                $validator->errors()->add(
                    'imei_1',
                    'Give the IMEI, or a serial number. A handset with neither cannot be looked up when the customer comes back with it.',
                );
            }

            $id = $this->route('handset')?->id;

            // Checked against the normalised digits, because that is what is stored.
            foreach (['imei_1', 'imei_2'] as $field) {
                $digits = Imei::normalize($this->input($field));

                if ($digits === null) {
                    continue;
                }

                $clash = Handset::query()
                    ->where(fn ($q) => $q->where('imei_1', $digits)->orWhere('imei_2', $digits))
                    ->when($id, fn ($q) => $q->whereKeyNot($id))
                    ->exists();

                if ($clash) {
                    $validator->errors()->add(
                        $field,
                        'Another handset is already registered under this IMEI — usually the same box booked in twice.',
                    );
                }
            }

            // The same number in both slots is a copy-paste, not a dual-SIM handset.
            if ($this->filled('imei_1') && $this->filled('imei_2')
                && Imei::normalize($this->input('imei_1')) === Imei::normalize($this->input('imei_2'))) {
                $validator->errors()->add('imei_2', 'Both IMEI slots hold the same number.');
            }

            // A sold handset the register cannot attribute to anyone is the state
            // that makes warranty and insurance unanswerable later.
            if ($this->input('status') === 'sold' && ! $this->filled('customer_id')) {
                $validator->errors()->add(
                    'customer_id',
                    'Name the buyer. A handset marked sold with no owner cannot have cover registered against it.',
                );
            }
        });
    }
}
