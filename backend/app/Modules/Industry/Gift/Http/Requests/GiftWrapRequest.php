<?php

declare(strict_types=1);

namespace App\Modules\Industry\Gift\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GiftWrapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        return [
            /**
             * Required on create: a parcel with no wrapping chosen is not a
             * parcel, it is a shopping bag. Everything else about it can be
             * filled in later, including who it is for — plenty of parcels are
             * taken in while the customer is still deciding what to write.
             */
            'wrap_option_id' => [
                $this->isMethod('post') ? 'required' : 'sometimes',
                Rule::exists('gift_wrap_options', 'id'),
            ],
            'customer_id' => ['nullable', 'exists:customers,id'],
            'invoice_id' => ['nullable', 'exists:invoices,id'],
            'sales_order_id' => ['nullable', 'exists:sales_orders,id'],
            'occasion_id' => ['nullable', 'exists:gift_occasions,id'],

            'recipient_name' => ['nullable', 'string', 'max:160'],
            'recipient_phone' => ['nullable', 'string', 'max:20'],
            'sender_name' => ['nullable', 'string', 'max:160'],
            // The card. Long enough for a verse, short enough to fit on one.
            'message' => ['nullable', 'string', 'max:500'],
            'hide_prices' => ['nullable', 'boolean'],

            'promised_at' => ['nullable', 'date'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            /**
             * Not accepted from the client. The charge is snapshotted from the
             * chosen option by the controller, and status moves only through the
             * wrap/collect/cancel actions — a queue whose state can be set to
             * anything from a form is a queue that quietly loses parcels.
             */
            'notes' => ['nullable', 'string'],
        ];
    }
}
