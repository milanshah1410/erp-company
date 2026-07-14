@extends('documents.layout', ['docTitle' => 'PURCHASE INVOICE', 'docSubtitle' => 'Vendor bill'])

@section('content')
    @php
        $inv = $purchaseInvoice;
        $items = $inv->items ?? collect();
        $supplier = $inv->supplier;
    @endphp

    <table class="meta">
        <tr>
            <td>
                <div class="meta-label">Bill No.</div>
                <div class="meta-value">{{ $inv->invoice_number }}</div>
            </td>
            <td>
                <div class="meta-label">Bill Date</div>
                <div class="meta-value">{{ optional($inv->invoice_date)->format('d M Y') }}</div>
            </td>
            <td>
                <div class="meta-label">Against PO</div>
                <div class="meta-value">{{ $inv->purchaseOrder?->po_number ?: '—' }}</div>
            </td>
            <td>
                <div class="meta-label">Match</div>
                <div class="meta-value"><span class="badge">{{ ucfirst($inv->match_status) }}</span></div>
            </td>
        </tr>
    </table>

    <table class="parties">
        <tr>
            <td>
                @include('documents.partials.party', [
                    'label' => 'Supplier',
                    'name' => $supplier?->name,
                    'lines' => [
                        $supplier?->company_name,
                        collect([$supplier?->address, $supplier?->city, $supplier?->state, $supplier?->pincode])->filter()->implode(', '),
                        $supplier?->phone ? 'Ph: '.$supplier->phone : null,
                        $supplier?->gst_number ? 'GSTIN: '.$supplier->gst_number : null,
                    ],
                ])
            </td>
            <td>
                @include('documents.partials.party', [
                    'label' => 'Payment',
                    'name' => 'Due ' . (optional($inv->due_date)->format('d M Y') ?: 'on receipt'),
                    'lines' => ['Status: ' . ucfirst($inv->status)],
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
                <th class="num">Unit Cost</th>
                <th class="num">Tax %</th>
                <th class="num">Tax Amt</th>
                <th class="num">Line Total</th>
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
                    <td class="num">{{ $currency }} {{ number_format((float) $line->unit_cost, 2) }}</td>
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
            <td class="value">{{ $currency }} {{ number_format((float) $inv->subtotal, 2) }}</td>
        </tr>
        <tr>
            <td class="label">Tax</td>
            <td class="value">{{ $currency }} {{ number_format((float) $inv->tax_amount, 2) }}</td>
        </tr>
        <tr class="grand">
            <td class="label">Total</td>
            <td class="value">{{ $currency }} {{ number_format((float) $inv->total, 2) }}</td>
        </tr>
    </table>

    @if ($inv->notes)
        <div class="section-title">Notes</div>
        <div class="notes">{{ $inv->notes }}</div>
    @endif
@endsection
