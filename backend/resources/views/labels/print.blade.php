<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 8mm; }
        body { font-family: DejaVu Sans, sans-serif; }
        .grid { display: flex; flex-wrap: wrap; }
        .label {
            width: 50mm;
            height: 30mm;
            box-sizing: border-box;
            border: 0.5px dashed #999;
            padding: 2mm;
            margin: 1mm;
            text-align: center;
            page-break-inside: avoid;
            overflow: hidden;
        }
        .label .name { font-size: 8px; font-weight: bold; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .label .sub { font-size: 7px; color: #444; }
        .label img { height: 12mm; margin: 1mm 0; }
        .label .value { font-size: 7px; letter-spacing: 0.5px; }
        .label .price { font-size: 8px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="grid">
        @foreach ($labels as $label)
            <div class="label">
                <div class="name">{{ $label['line1'] }}</div>
                <div class="sub">{{ $label['line2'] }}</div>
                <img src="{{ $label['image'] }}" alt="barcode">
                <div class="value">{{ $label['value'] }}</div>
                @if ($label['price'] !== null)
                    <div class="price">&#8377; {{ number_format((float) $label['price'], 2) }}</div>
                @endif
            </div>
        @endforeach
    </div>
</body>
</html>
