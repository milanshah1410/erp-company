@extends('documents.layout', ['docTitle' => 'MANUFACTURING SLIP', 'docSubtitle' => 'Production work order'])

@section('content')
    @php
        $mo = $manufacturingOrder;
        $materials = $mo->materials ?? collect();
        $stages = $mo->stages ?? collect();
        $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
    @endphp

    <table class="meta">
        <tr>
            <td>
                <div class="meta-label">MO No.</div>
                <div class="meta-value">{{ $mo->mo_number }}</div>
            </td>
            <td>
                <div class="meta-label">Order Date</div>
                <div class="meta-value">{{ optional($mo->order_date)->format('d M Y') }}</div>
            </td>
            <td>
                <div class="meta-label">Target Completion</div>
                <div class="meta-value">{{ optional($mo->expected_completion_date)->format('d M Y') ?: '—' }}</div>
            </td>
            <td>
                <div class="meta-label">Status</div>
                <div class="meta-value"><span class="badge">{{ ucfirst(str_replace('_', ' ', $mo->status)) }}</span></div>
            </td>
        </tr>
    </table>

    <table class="parties">
        <tr>
            <td>
                @include('documents.partials.party', [
                    'label' => 'Product to Manufacture',
                    'name' => $mo->product?->name,
                    'lines' => [
                        $mo->product?->sku ? 'SKU: '.$mo->product->sku : null,
                        'Warehouse: ' . ($mo->warehouse?->name ?: '—'),
                    ],
                ])
            </td>
            <td>
                @include('documents.partials.party', [
                    'label' => 'Quantities',
                    'name' => 'Target: ' . $qty($mo->quantity_to_produce),
                    'lines' => [
                        'Produced: ' . $qty($mo->quantity_produced),
                        $mo->started_at ? 'Started: '.$mo->started_at->format('d M Y') : null,
                        $mo->completed_at ? 'Completed: '.$mo->completed_at->format('d M Y') : null,
                    ],
                ])
            </td>
        </tr>
    </table>

    <div class="section-title">Bill of Materials</div>
    <table class="items">
        <thead>
            <tr>
                <th style="width:28px;">#</th>
                <th>Material</th>
                <th class="num">Qty Required</th>
                <th class="num">Qty Consumed</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($materials as $i => $m)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td class="item-name">{{ $m->product?->name }}</td>
                    <td class="num">{{ $qty($m->quantity) }}</td>
                    <td class="num">{{ $qty($m->consumed_quantity) }}</td>
                    <td>{{ $m->remarks ?: '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="center muted">No materials listed.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="section-title">Production Stages</div>
    <table class="items">
        <thead>
            <tr>
                <th style="width:28px;">#</th>
                <th>Stage</th>
                <th>Assigned To</th>
                <th>Start</th>
                <th>End</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($stages as $i => $s)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td class="item-name">{{ $s->name }}</td>
                    <td>{{ $s->employee?->name ?: '—' }}</td>
                    <td>{{ optional($s->start_date)->format('d M Y') ?: '—' }}</td>
                    <td>{{ optional($s->end_date)->format('d M Y') ?: '—' }}</td>
                    <td>{{ ucfirst(str_replace('_', ' ', $s->status)) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="center muted">No stages defined.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($mo->notes)
        <div class="section-title">Notes</div>
        <div class="notes">{{ $mo->notes }}</div>
    @endif

    <table class="sign-row">
        <tr>
            <td>
                <div style="height:36px;"></div>
                <div class="sign-line" style="display:inline-block; min-width:180px;">Supervisor</div>
            </td>
            <td class="right">
                <div style="height:36px;"></div>
                <div class="sign-line" style="display:inline-block; min-width:180px;">Store Incharge</div>
            </td>
        </tr>
    </table>
@endsection
