@extends('documents.layout', ['docTitle' => 'TAILOR JOB CARD', 'docSubtitle' => 'Piece-work issue slip'])

@section('content')
    @php
        $tw = $tailorWork;
        $tailor = $tw->tailor;
        $completions = $tw->completions ?? collect();
        $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
        $amount = (float) $tw->issued_qty * (float) $tw->rate;
    @endphp

    <table class="meta">
        <tr>
            <td>
                <div class="meta-label">Work No.</div>
                <div class="meta-value">{{ $tw->work_number }}</div>
            </td>
            <td>
                <div class="meta-label">Issue Date</div>
                <div class="meta-value">{{ optional($tw->issue_date)->format('d M Y') }}</div>
            </td>
            <td>
                <div class="meta-label">Due Date</div>
                <div class="meta-value">{{ optional($tw->due_date)->format('d M Y') ?: '—' }}</div>
            </td>
            <td>
                <div class="meta-label">Status</div>
                <div class="meta-value"><span class="badge">{{ ucfirst($tw->status) }}</span></div>
            </td>
        </tr>
    </table>

    <table class="parties">
        <tr>
            <td>
                @include('documents.partials.party', [
                    'label' => 'Tailor',
                    'name' => $tailor?->name,
                    'lines' => [
                        $tailor?->code ? 'Code: '.$tailor->code : null,
                        $tailor?->mobile ? 'Mob: '.$tailor->mobile : null,
                        $tailor?->skill ? 'Skill: '.$tailor->skill : null,
                        $tailor?->address,
                    ],
                ])
            </td>
            <td>
                @include('documents.partials.party', [
                    'label' => 'Garment',
                    'name' => $tw->product?->name,
                    'lines' => [
                        $tw->variant ? $tw->variant->name.' · '.$tw->variant->sku : ($tw->product?->sku ? 'SKU: '.$tw->product->sku : null),
                        $tw->rateCard?->name ? 'Rate Card: '.$tw->rateCard->name : null,
                    ],
                ])
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>Description</th>
                <th class="num">Issued Qty</th>
                <th class="num">Completed Qty</th>
                <th class="num">Rate</th>
                <th class="num">Est. Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="item-name">{{ $tw->product?->name }}{{ $tw->variant ? ' — '.$tw->variant->name : '' }}</td>
                <td class="num">{{ $qty($tw->issued_qty) }}</td>
                <td class="num">{{ $qty($tw->completed_qty) }}</td>
                <td class="num">{{ $currency }} {{ number_format((float) $tw->rate, 2) }}</td>
                <td class="num">{{ $currency }} {{ number_format($amount, 2) }}</td>
            </tr>
        </tbody>
    </table>

    @if ($completions->isNotEmpty())
        <div class="section-title">Completions</div>
        <table class="items">
            <thead>
                <tr>
                    <th style="width:28px;">#</th>
                    <th>Date</th>
                    <th class="num">Qty</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($completions as $i => $c)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td>{{ optional($c->completed_at)->format('d M Y') ?: '—' }}</td>
                        <td class="num">{{ $qty($c->qty) }}</td>
                        <td>{{ $c->notes ?: '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($tw->notes)
        <div class="section-title">Notes</div>
        <div class="notes">{{ $tw->notes }}</div>
    @endif

    <table class="sign-row">
        <tr>
            <td>
                <div style="height:36px;"></div>
                <div class="sign-line" style="display:inline-block; min-width:180px;">Tailor's Signature</div>
            </td>
            <td class="right">
                <div style="height:36px;"></div>
                <div class="sign-line" style="display:inline-block; min-width:180px;">Issued By</div>
            </td>
        </tr>
    </table>
@endsection
