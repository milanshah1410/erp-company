<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PrintLabelsRequest extends FormRequest
{
    /** The four label formats offered by SRS §21 "Barcode & Label". */
    public const TEMPLATES = ['barcode', 'qr', 'sticker', 'price_tag'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Which of the four §21 label formats to render.
            'template' => ['nullable', Rule::in(self::TEMPLATES)],
            // Symbology. For the barcode/qr templates it is implied; for the
            // sticker/price-tag templates it selects the code drawn on the label.
            'label_type' => ['nullable', Rule::in(['code128', 'qr'])],
            // Cosmetic toggles honoured by the sticker/price-tag layouts.
            'show_company' => ['nullable', 'boolean'],
            'show_price' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.type' => ['required', Rule::in(['product', 'variant'])],
            'items.*.id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:500'],
        ];
    }
}
