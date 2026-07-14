{{-- Reusable party (customer/supplier/vendor) address box.
     Expects: $label (string), $name (string), $lines (array of strings). --}}
<div class="party">
    <div class="party-label">{{ $label }}</div>
    <div class="party-name">{{ $name ?: '—' }}</div>
    @foreach (collect($lines ?? [])->filter() as $line)
        <div class="party-line">{{ $line }}</div>
    @endforeach
</div>
