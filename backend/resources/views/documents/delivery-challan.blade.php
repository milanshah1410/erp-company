@extends('documents.layout', ['docTitle' => 'DELIVERY CHALLAN', 'docSubtitle' => 'Not a tax invoice'])

@section('content')
    @php
        $order = $salesOrder;
        $items = $order->items ?? collect();
        $customer = $order->customer;
        $totalQty = $items->sum(fn ($l) => (float) $l->quantity);
    @endphp

    <table class="meta">
        <tr>
            <td>
                <div class="meta-label">Challan Ref.</div>
                <div class="meta-value">{{ $order->so_number }}</div>
            </td>
            <td>
                <div class="meta-label">Order Date</div>
                <div class="meta-value">{{ optional($order->order_date)->format('d M Y') }}</div>
            </td>
            <td>
                <div class="meta-label">Dispatched From</div>
                <div class="meta-value">{{ $order->warehouse?->name ?: '—' }}</div>
            </td>
            <td>
                <div class="meta-label">Fulfilment</div>
                <div class="meta-value"><span class="badge">{{ ucfirst(str_replace('_', ' ', $order->fulfillment_stage ?: $order->status)) }}</span></div>
            </td>
        </tr>
    </table>

    <table class="parties">
        <tr>
            <td>
                @include('documents.partials.party', [
                    'label' => 'Consignee (Ship To)',
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
                    'label' => 'Dispatch Details',
                    'name' => $order->shipped_at ? 'Shipped '.$order->shipped_at->format('d M Y') : 'Pending dispatch',
                    'lines' => [
                        $order->packed_at ? 'Packed: '.$order->packed_at->format('d M Y') : null,
                        $order->channel ? 'Channel: '.ucfirst($order->channel) : null,
                    ],
                ])
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width:28px;">#</th>
                <th>Item Description</th>
                <th>SKU</th>
                <th class="num">Qty</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($items as $i => $line)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>
                        <div class="item-name">{{ $line->product?->name }}</div>
                        @if ($line->variant)
                            <div class="item-sub">{{ $line->variant->name }}</div>
                        @endif
                    </td>
                    <td>{{ $line->variant?->sku ?: $line->product?->sku ?: '—' }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $line->quantity, 3), '0'), '.') }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="center muted">No line items.</td></tr>
            @endforelse
            <tr>
                <td colspan="3" class="right"><strong>Total Quantity</strong></td>
                <td class="num"><strong>{{ rtrim(rtrim(number_format($totalQty, 3), '0'), '.') }}</strong></td>
            </tr>
        </tbody>
    </table>

    @if ($order->notes)
        <div class="section-title">Notes</div>
        <div class="notes">{{ $order->notes }}</div>
    @endif

    <table class="sign-row">
        <tr>
            <td>
                <div style="height:36px;"></div>
                <div class="sign-line" style="display:inline-block; min-width:180px;">Received in good condition</div>
            </td>
            <td class="right">
                <div style="height:36px;"></div>
                <div class="sign-line" style="display:inline-block; min-width:180px;">Authorised Signatory</div>
            </td>
        </tr>
    </table>
@endsection
