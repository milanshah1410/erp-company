@extends('documents.layout', ['docTitle' => 'TAX INVOICE'])

@section('content')
    @php
        $so = $invoice->salesOrder;
        $items = $so?->items ?? collect();
        $customer = $invoice->customer;
        $balance = $invoice->balanceDue();
    @endphp

    <table class="meta">
        <tr>
            <td>
                <div class="meta-label">Invoice No.</div>
                <div class="meta-value">{{ $invoice->invoice_number }}</div>
            </td>
            <td>
                <div class="meta-label">Invoice Date</div>
                <div class="meta-value">{{ optional($invoice->invoice_date)->format('d M Y') }}</div>
            </td>
            <td>
                <div class="meta-label">Due Date</div>
                <div class="meta-value">{{ optional($invoice->due_date)->format('d M Y') ?: '—' }}</div>
            </td>
            <td>
                <div class="meta-label">Status</div>
                <div class="meta-value"><span class="badge">{{ ucfirst($invoice->status) }}</span></div>
            </td>
        </tr>
    </table>

    <table class="parties">
        <tr>
            <td>
                @include('documents.partials.party', [
                    'label' => 'Billed To',
                    'name' => $customer?->name,
                    'lines' => [
                        $customer?->company_name,
                        collect([$customer?->address, $customer?->city, $customer?->state, $customer?->pincode])->filter()->implode(', '),
                        $customer?->phone ? 'Ph: '.$customer->phone : null,
                        $customer?->gst_number ? 'GSTIN: '.$customer->gst_number : null,
                    ],
                ])
            </td>
            <td>
                @include('documents.partials.party', [
                    'label' => 'Reference',
                    'name' => $so?->so_number ? 'Sales Order '.$so->so_number : 'Direct Invoice',
                    'lines' => [
                        $so && $so->order_date ? 'Order Date: '.$so->order_date->format('d M Y') : null,
                        $invoice->broker?->name ? 'Broker: '.$invoice->broker->name : null,
                    ],
                ])
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width:28px;">#</th>
                <th>Item</th>
                <th class="num">Qty</th>
                <th class="num">Rate</th>
                <th class="num">Tax %</th>
                <th class="num">Tax Amt</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($items as $i => $line)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>
                        <div class="item-name">{{ $line->product?->name }}</div>
                        @if ($line->variant)
                            <div class="item-sub">{{ $line->variant->name }} &middot; {{ $line->variant->sku }}</div>
                        @elseif ($line->product?->sku)
                            <div class="item-sub">{{ $line->product->sku }}</div>
                        @endif
                    </td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $line->quantity, 3), '0'), '.') }}</td>
                    <td class="num">{{ $currency }} {{ number_format((float) $line->unit_price, 2) }}</td>
                    <td class="num">{{ number_format((float) $line->tax_percent, 2) }}</td>
                    <td class="num">{{ $currency }} {{ number_format((float) $line->tax_amount, 2) }}</td>
                    <td class="num">{{ $currency }} {{ number_format((float) $line->line_total, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="center muted">No line items.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="totals">
        @if ($so)
            <tr>
                <td class="label">Subtotal</td>
                <td class="value">{{ $currency }} {{ number_format((float) $so->subtotal, 2) }}</td>
            </tr>
            @if ((float) $so->item_discount_total + (float) $so->discount_amount > 0)
                <tr>
                    <td class="label">Discount</td>
                    <td class="value">− {{ $currency }} {{ number_format((float) $so->item_discount_total + (float) $so->discount_amount, 2) }}</td>
                </tr>
            @endif
            <tr>
                <td class="label">Tax</td>
                <td class="value">{{ $currency }} {{ number_format((float) $so->tax_amount, 2) }}</td>
            </tr>
            @if ((float) $so->round_off != 0)
                <tr>
                    <td class="label">Round Off</td>
                    <td class="value">{{ $currency }} {{ number_format((float) $so->round_off, 2) }}</td>
                </tr>
            @endif
        @endif
        <tr class="grand">
            <td class="label">Total</td>
            <td class="value">{{ $currency }} {{ number_format((float) $invoice->total, 2) }}</td>
        </tr>
        @if ((float) $invoice->paid_amount > 0)
            <tr>
                <td class="label">Paid</td>
                <td class="value">{{ $currency }} {{ number_format((float) $invoice->paid_amount, 2) }}</td>
            </tr>
        @endif
        @if ((float) $invoice->credited_amount > 0)
            <tr>
                <td class="label">Credited</td>
                <td class="value">{{ $currency }} {{ number_format((float) $invoice->credited_amount, 2) }}</td>
            </tr>
        @endif
        <tr>
            <td class="label">Balance Due</td>
            <td class="value">{{ $currency }} {{ number_format($balance, 2) }}</td>
        </tr>
    </table>

    <table class="sign-row">
        <tr>
            <td>&nbsp;</td>
            <td class="right">
                <div style="height:36px;"></div>
                <div class="sign-line" style="display:inline-block; min-width:180px;">Authorised Signatory</div>
            </td>
        </tr>
    </table>
@endsection
