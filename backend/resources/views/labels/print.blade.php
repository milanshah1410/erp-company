<?php /** @var array $labels; @var string $template; @var array|null $company; @var string $currency */ ?>
@php($template = $template ?? 'barcode')
@php($currency = $currency ?? '₹')
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 6mm; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; margin: 0; color: #111827; }

        .label {
            display: inline-block;
            vertical-align: top;
            box-sizing: border-box;
            border: 0.5px dashed #b0b0b0;
            margin: 0.6mm;
            padding: 1.5mm;
            text-align: center;
            overflow: hidden;
            page-break-inside: avoid;
        }
        .label img { display: block; margin: 0.5mm auto; }
        .name { font-weight: bold; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sub  { color: #4b5563; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .value { letter-spacing: 0.4px; }
        .company { color: #374151; font-weight: bold; text-transform: uppercase; letter-spacing: 0.3px; }

        /* ---- Barcode Printing: compact 1D barcode ---- */
        .tpl-barcode { width: 40mm; height: 22mm; }
        .tpl-barcode .name  { font-size: 7px; }
        .tpl-barcode img    { height: 11mm; max-width: 100%; }
        .tpl-barcode .value { font-size: 7px; }

        /* ---- QR Label: square QR code ---- */
        .tpl-qr { width: 32mm; height: 34mm; }
        .tpl-qr .name  { font-size: 7px; }
        .tpl-qr img    { width: 20mm; height: 20mm; }
        .tpl-qr .value { font-size: 6px; }

        /* ---- Product Sticker: richer product detail ---- */
        .tpl-sticker { width: 50mm; height: 30mm; text-align: left; }
        .tpl-sticker .company { font-size: 6px; }
        .tpl-sticker .name  { font-size: 9px; text-align: left; }
        .tpl-sticker .sub   { font-size: 7px; text-align: left; }
        .tpl-sticker .meta  { font-size: 6px; color: #6b7280; margin-bottom: 0.5mm; }
        .tpl-sticker img    { height: 8mm; margin: 0.5mm 0; }
        .tpl-sticker .value { font-size: 6px; text-align: left; }
        .tpl-sticker .price { font-size: 9px; font-weight: bold; }

        /* ---- Price Tag: prominent selling price ---- */
        .tpl-price_tag { width: 50mm; height: 30mm; }
        .tpl-price_tag .company { font-size: 6px; }
        .tpl-price_tag .name  { font-size: 8px; }
        .tpl-price_tag .price { font-size: 18px; font-weight: bold; color: #111827; margin: 0.5mm 0; }
        .tpl-price_tag img    { height: 6mm; }
        .tpl-price_tag .value { font-size: 6px; }
    </style>
</head>
<body>
    @foreach ($labels as $label)
        <div class="label tpl-{{ $template }}">
            @switch($template)
                @case('qr')
                    <div class="name">{{ $label['line1'] }}</div>
                    <img src="{{ $label['image'] }}" alt="qr">
                    <div class="value">{{ $label['value'] }}</div>
                    @break

                @case('sticker')
                    @if (!empty($company)) <div class="company">{{ $company['name'] }}</div> @endif
                    <div class="name">{{ $label['line1'] }}</div>
                    <div class="sub">{{ $label['line2'] }}</div>
                    @php($meta = collect([$label['category'] ?? null, $label['brand'] ?? null])->filter()->implode(' · '))
                    @if ($meta !== '') <div class="meta">{{ $meta }}</div> @endif
                    <img src="{{ $label['image'] }}" alt="code">
                    <div class="value">{{ $label['value'] }}</div>
                    @if (!is_null($label['price']))
                        <div class="price">{{ $currency }} {{ number_format((float) $label['price'], 2) }}</div>
                    @endif
                    @break

                @case('price_tag')
                    @if (!empty($company)) <div class="company">{{ $company['name'] }}</div> @endif
                    <div class="name">{{ $label['line1'] }}</div>
                    @if (!is_null($label['price']))
                        <div class="price">{{ $currency }} {{ number_format((float) $label['price'], 2) }}</div>
                    @endif
                    <img src="{{ $label['image'] }}" alt="code">
                    <div class="value">{{ $label['value'] }}</div>
                    @break

                @default {{-- barcode --}}
                    <div class="name">{{ $label['line1'] }}</div>
                    <img src="{{ $label['image'] }}" alt="barcode">
                    <div class="value">{{ $label['value'] }}</div>
            @endswitch
        </div>
    @endforeach
</body>
</html>
