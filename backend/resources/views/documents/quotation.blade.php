@extends('documents.layout', ['docTitle' => 'QUOTATION'])

@section('content')
    @php
        $items = $quotation->items ?? collect();
        $customer = $quotation->customer;
    @endphp

    <table class="meta">
        <tr>
            <td>
                <div class="meta-label">Quotation No.</div>
                <div class="meta-value">{{ $quotation->quote_number }}</div>
            </td>
            <td>
                <div class="meta-label">Date</div>
                <div class="meta-value">{{ optional($quotation->quote_date)->format('d M Y') }}</div>
            </td>
            <td>
                <div class="meta-label">Valid Until</div>
                <div class="meta-value">{{ optional($quotation->valid_until)->format('d M Y') ?: '—' }}</div>
            </td>
            <td>
                <div class="meta-label">Status</div>
                <div class="meta-value"><span class="badge">{{ ucfirst($quotation->status) }}</span></div>
            </td>
        </tr>
    </table>

    <table class="parties">
        <tr>
            <td>
                @include('documents.partials.party', [
                    'label' => 'Quotation For',
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
                    'label' => 'From',
                    'name' => $quotation->warehouse?->name,
                    'lines' => [],
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
        <tr>
            <td class="label">Subtotal</td>
            <td class="value">{{ $currency }} {{ number_format((float) $quotation->subtotal, 2) }}</td>
        </tr>
        @if ((float) $quotation->item_discount_total + (float) $quotation->discount_amount > 0)
            <tr>
                <td class="label">Discount</td>
                <td class="value">− {{ $currency }} {{ number_format((float) $quotation->item_discount_total + (float) $quotation->discount_amount, 2) }}</td>
            </tr>
        @endif
        <tr>
            <td class="label">Tax</td>
            <td class="value">{{ $currency }} {{ number_format((float) $quotation->tax_amount, 2) }}</td>
        </tr>
        @if ((float) $quotation->round_off != 0)
            <tr>
                <td class="label">Round Off</td>
                <td class="value">{{ $currency }} {{ number_format((float) $quotation->round_off, 2) }}</td>
            </tr>
        @endif
        <tr class="grand">
            <td class="label">Total</td>
            <td class="value">{{ $currency }} {{ number_format((float) $quotation->total, 2) }}</td>
        </tr>
    </table>

    @if ($quotation->notes)
        <div class="section-title">Notes</div>
        <div class="notes">{{ $quotation->notes }}</div>
    @endif

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
