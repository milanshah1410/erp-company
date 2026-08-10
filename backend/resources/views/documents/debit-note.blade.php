{{--
    Purchase Debit Note (retail base §3). What the shop posts to its supplier
    when goods have gone back: the lines, the rate they were bought at, and the
    amount the vendor is being asked to credit.

    Reads as a claim rather than as an internal stock document, which is why the
    supplier is the addressed party and the shop's own warehouse is demoted to a
    meta field. The GRN reference is prominent on purpose — it is the one thing a
    vendor's accounts clerk searches on before granting credit.
--}}
@extends('documents.layout', ['docTitle' => 'DEBIT NOTE', 'docSubtitle' => 'Claim for goods returned'])

@section('content')
    @php
        $items = $return->items ?? collect();
        $supplier = $return->supplier;
        $subtotal = $return->debitNoteSubtotal();
        $taxAmount = $return->debitNoteTaxAmount();
        $total = $return->debitNoteTotal();
        $totalQty = $items->sum(fn ($l) => (float) $l->quantity);
        $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
    @endphp

    <table class="meta">
        <tr>
            <td>
                <div class="meta-label">Debit Note No.</div>
                <div class="meta-value">{{ $return->debit_note_number }}</div>
            </td>
            <td>
                <div class="meta-label">Date</div>
                <div class="meta-value">{{ optional($return->debit_note_date)->format('d M Y') ?: '—' }}</div>
            </td>
            <td>
                <div class="meta-label">Against GRN</div>
                <div class="meta-value">{{ $return->goodsReceipt?->grn_number ?: '—' }}</div>
            </td>
            <td>
                <div class="meta-label">Our Return Ref.</div>
                <div class="meta-value">{{ $return->return_number }}</div>
            </td>
        </tr>
    </table>

    <table class="parties">
        <tr>
            <td>
                @include('documents.partials.party', [
                    'label' => 'To (Supplier)',
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
                    'label' => 'Goods Returned From',
                    'name' => $return->warehouse?->name ?: '—',
                    'lines' => [
                        'Returned on: '.(optional($return->return_date)->format('d M Y') ?: '—'),
                        $return->approved_at ? 'Despatched: '.$return->approved_at->format('d M Y') : null,
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
                <th>HSN</th>
                <th>Reason for Return</th>
                <th class="num">Qty</th>
                <th class="num">Rate</th>
                <th class="num">Value</th>
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
                        @if ($line->batch_number)
                            <div class="item-sub">Batch: {{ $line->batch_number }}</div>
                        @endif
                    </td>
                    <td>{{ $line->variant?->sku ?: $line->product?->sku ?: '—' }}</td>
                    <td>{{ $line->product?->hsn_code ?: '—' }}</td>
                    <td>{{ $line->reason ?: '—' }}</td>
                    <td class="num">{{ $qty($line->quantity) }}</td>
                    <td class="num">{{ $currency }}{{ number_format((float) $line->unit_cost, 2) }}</td>
                    <td class="num">{{ $currency }}{{ number_format((float) $line->quantity * (float) $line->unit_cost, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="center muted">No line items.</td></tr>
            @endforelse
            <tr>
                <td colspan="5" class="right"><strong>Total</strong></td>
                <td class="num"><strong>{{ $qty($totalQty) }}</strong></td>
                <td></td>
                <td class="num"><strong>{{ $currency }}{{ number_format($subtotal, 2) }}</strong></td>
            </tr>
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="label">Goods Value</td>
            <td class="value">{{ $currency }}{{ number_format($subtotal, 2) }}</td>
        </tr>
        @if ((float) $return->debit_note_tax_percent > 0)
            <tr>
                <td class="label">Tax @ {{ rtrim(rtrim(number_format((float) $return->debit_note_tax_percent, 2), '0'), '.') }}%</td>
                <td class="value">{{ $currency }}{{ number_format($taxAmount, 2) }}</td>
            </tr>
        @endif
        <tr class="grand">
            <td class="label">Amount Debited</td>
            <td class="value">{{ $currency }}{{ number_format($total, 2) }}</td>
        </tr>
    </table>

    <div class="section-title">Declaration</div>
    <div class="notes">
        The goods listed above have been returned to you and your account has been debited with
        {{ $currency }}{{ number_format($total, 2) }}. Please issue your credit note against this
        reference.
        @if ($return->notes)
            <br><br>{{ $return->notes }}
        @endif
    </div>

    <table class="sign-row">
        <tr>
            <td>
                <div style="height:36px;"></div>
                <div class="sign-line" style="display:inline-block; min-width:180px;">Supplier's Acknowledgement</div>
            </td>
            <td class="right">
                <div style="height:36px;"></div>
                <div class="sign-line" style="display:inline-block; min-width:180px;">For {{ $company['name'] }}</div>
            </td>
        </tr>
    </table>
@endsection
