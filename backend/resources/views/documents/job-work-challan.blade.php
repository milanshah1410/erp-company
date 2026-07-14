@extends('documents.layout', ['docTitle' => 'JOB WORK CHALLAN', 'docSubtitle' => 'Delivery challan for job work (Rule 45)'])

@section('content')
    @php
        $jw = $jobWork;
        $items = $jw->items ?? collect();
        $supplier = $jw->supplier;
        $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
    @endphp

    <table class="meta">
        <tr>
            <td>
                <div class="meta-label">Challan No.</div>
                <div class="meta-value">{{ $jw->job_work_number }}</div>
            </td>
            <td>
                <div class="meta-label">Date</div>
                <div class="meta-value">{{ optional($jw->job_work_date)->format('d M Y') }}</div>
            </td>
            <td>
                <div class="meta-label">Job Type</div>
                <div class="meta-value">{{ $jw->job_type ? ucwords(str_replace('_', ' ', $jw->job_type)) : '—' }}</div>
            </td>
            <td>
                <div class="meta-label">Status</div>
                <div class="meta-value"><span class="badge">{{ ucfirst($jw->status) }}</span></div>
            </td>
        </tr>
    </table>

    <table class="parties">
        <tr>
            <td>
                @include('documents.partials.party', [
                    'label' => 'Job Worker (Vendor)',
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
                    'label' => 'Dispatch',
                    'name' => 'From ' . ($jw->warehouse?->name ?: '—'),
                    'lines' => [
                        $jw->issued_date ? 'Issued: '.$jw->issued_date->format('d M Y') : null,
                        $jw->expected_date ? 'Expected back: '.$jw->expected_date->format('d M Y') : null,
                        $jw->completed_date ? 'Completed: '.$jw->completed_date->format('d M Y') : null,
                    ],
                ])
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width:28px;">#</th>
                <th>Material Issued</th>
                <th class="num">Issued</th>
                <th class="num">Received</th>
                <th class="num">Damage</th>
                <th class="num">Shortage</th>
                <th class="num">Pending</th>
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
                        @if ($line->finishedProduct)
                            <div class="item-sub">→ Finished: {{ $line->finishedProduct->name }}</div>
                        @endif
                    </td>
                    <td class="num">{{ $qty($line->issued_qty) }}</td>
                    <td class="num">{{ $qty($line->received_qty) }}</td>
                    <td class="num">{{ $qty($line->damage_qty) }}</td>
                    <td class="num">{{ $qty($line->shortage_qty) }}</td>
                    <td class="num">{{ $qty($line->pending_qty) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="center muted">No materials issued.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($jw->notes)
        <div class="section-title">Notes</div>
        <div class="notes">{{ $jw->notes }}</div>
    @endif

    <table class="sign-row">
        <tr>
            <td>
                <div style="height:36px;"></div>
                <div class="sign-line" style="display:inline-block; min-width:180px;">Received by (Vendor)</div>
            </td>
            <td class="right">
                <div style="height:36px;"></div>
                <div class="sign-line" style="display:inline-block; min-width:180px;">Authorised Signatory</div>
            </td>
        </tr>
    </table>
@endsection
