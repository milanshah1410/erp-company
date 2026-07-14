<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $docTitle ?? 'Document' }}</title>
    <style>
        @page { margin: 14mm 12mm; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; margin: 0; }
        .muted { color: #6b7280; }
        .right { text-align: right; }
        .center { text-align: center; }
        .nowrap { white-space: nowrap; }

        /* Header */
        .doc-head { width: 100%; border-bottom: 2px solid #111827; padding-bottom: 10px; margin-bottom: 14px; }
        .doc-head td { vertical-align: top; }
        .company-name { font-size: 18px; font-weight: bold; color: #111827; }
        .company-legal { font-size: 10px; color: #6b7280; }
        .company-meta { font-size: 10px; color: #4b5563; margin-top: 3px; line-height: 1.45; }
        .doc-title { font-size: 20px; font-weight: bold; color: #111827; letter-spacing: 0.5px; }
        .doc-subtitle { font-size: 10px; color: #6b7280; margin-top: 2px; }

        /* Meta grid (doc no / date / status ...) */
        .meta { width: 100%; margin-bottom: 14px; }
        .meta td { vertical-align: top; padding: 0; }
        .meta-label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.4px; color: #9ca3af; }
        .meta-value { font-size: 11px; color: #111827; font-weight: bold; padding-bottom: 6px; }

        /* Party boxes */
        .parties { width: 100%; margin-bottom: 14px; }
        .parties td { vertical-align: top; width: 50%; padding: 0 6px; }
        .parties td:first-child { padding-left: 0; }
        .parties td:last-child { padding-right: 0; }
        .party { border: 1px solid #e5e7eb; border-radius: 4px; padding: 8px 10px; height: 100%; }
        .party-label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.4px; color: #9ca3af; margin-bottom: 3px; }
        .party-name { font-weight: bold; font-size: 12px; color: #111827; }
        .party-line { font-size: 10px; color: #4b5563; line-height: 1.45; }

        /* Item / data tables */
        table.items { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        table.items th { background: #111827; color: #fff; font-size: 9px; text-transform: uppercase;
            letter-spacing: 0.4px; padding: 6px 7px; text-align: left; }
        table.items td { border-bottom: 1px solid #e5e7eb; padding: 6px 7px; font-size: 10px; }
        table.items tr:nth-child(even) td { background: #f9fafb; }
        table.items td.num, table.items th.num { text-align: right; white-space: nowrap; }
        table.items .item-name { font-weight: bold; color: #111827; }
        table.items .item-sub { font-size: 9px; color: #6b7280; }

        /* Totals */
        .totals { width: 45%; margin-left: 55%; border-collapse: collapse; }
        .totals td { padding: 4px 7px; font-size: 11px; }
        .totals td.label { color: #4b5563; }
        .totals td.value { text-align: right; font-weight: bold; color: #111827; }
        .totals tr.grand td { border-top: 2px solid #111827; font-size: 13px; padding-top: 7px; }

        /* Notes / signature / footer */
        .section-title { font-size: 9px; text-transform: uppercase; letter-spacing: 0.4px; color: #9ca3af; margin: 12px 0 4px; }
        .notes { font-size: 10px; color: #4b5563; border: 1px solid #e5e7eb; border-radius: 4px; padding: 8px 10px; }
        .badge { display: inline-block; font-size: 9px; text-transform: uppercase; letter-spacing: 0.4px;
            padding: 2px 8px; border-radius: 10px; background: #e5e7eb; color: #374151; }
        .sign-row { width: 100%; margin-top: 40px; }
        .sign-row td { width: 50%; vertical-align: bottom; font-size: 10px; color: #4b5563; }
        .sign-line { border-top: 1px solid #9ca3af; padding-top: 4px; }
        .foot { margin-top: 22px; padding-top: 8px; border-top: 1px solid #e5e7eb; font-size: 9px; color: #9ca3af; text-align: center; }
    </style>
</head>
<body>
    {{-- Letterhead --}}
    <table class="doc-head">
        <tr>
            <td>
                <div class="company-name">{{ $company['name'] }}</div>
                @if (!empty($company['legal_name']) && $company['legal_name'] !== $company['name'])
                    <div class="company-legal">{{ $company['legal_name'] }}</div>
                @endif
                <div class="company-meta">
                    @if (!empty($company['address'])){{ $company['address'] }}<br>@endif
                    @if (!empty($company['phone']))Ph: {{ $company['phone'] }}@endif
                    @if (!empty($company['email'])) &middot; {{ $company['email'] }}@endif
                    @if (!empty($company['gstin']))<br>GSTIN: {{ $company['gstin'] }}@endif
                    @if (!empty($company['pan'])) &middot; PAN: {{ $company['pan'] }}@endif
                </div>
            </td>
            <td class="right">
                <div class="doc-title">{{ $docTitle }}</div>
                @isset($docSubtitle)
                    <div class="doc-subtitle">{{ $docSubtitle }}</div>
                @endisset
            </td>
        </tr>
    </table>

    @yield('content')

    <div class="foot">
        Generated {{ $generatedAt }} &middot; This is a computer-generated document.
    </div>
</body>
</html>
