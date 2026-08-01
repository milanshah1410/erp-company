{{--
    The SaaS operator's tax invoice to a tenant (Volume 11 §Billing → GST).

    Everything GST-related is read off the invoice row, not from the live platform
    profile: the figures were snapshotted when the invoice was raised, so correcting
    a GSTIN typo next month cannot rewrite a document a customer has already filed.
    A zero-rated invoice (no seller GSTIN, or an export) simply has no tax block.
--}}
@extends('documents.layout', ['docTitle' => $invoice->hasTax() ? 'TAX INVOICE' : 'SUBSCRIPTION INVOICE'])

@section('content')
    @php
        $sub = $invoice->subscription;
        $plan = $sub?->plan;
        // Prefer the period the invoice was raised for; fall back to the
        // subscription's current window for rows raised before periods were stored.
        $from = $invoice->period_start ?? $sub?->current_period_start;
        $to = $invoice->period_end ?? $sub?->current_period_end;
        $period = $from && $to ? $from->format('d M Y').' – '.$to->format('d M Y') : null;

        $money = fn ($value) => $currency.' '.number_format((float) $value, 2);
        $seller = $context['seller'] ?? [];
        $customer = $context['customer'] ?? [];
        $stateName = $invoice->place_of_supply
            ? \App\Support\GstStateCodes::name($invoice->place_of_supply)
            : null;
        // Legacy rows carry no decomposition; treat the gross as the taxable value
        // so the totals block still balances.
        $taxable = (float) ($invoice->taxable_amount ?: $invoice->amount);
    @endphp

    <table class="meta">
        <tr>
            <td>
                <div class="meta-label">Invoice No.</div>
                <div class="meta-value">{{ $number }}</div>
            </td>
            <td>
                <div class="meta-label">Invoice Date</div>
                <div class="meta-value">{{ optional($invoice->created_at)->format('d M Y') }}</div>
            </td>
            <td>
                <div class="meta-label">{{ $invoice->status === 'paid' ? 'Paid On' : 'Due On' }}</div>
                <div class="meta-value">
                    {{ optional($invoice->status === 'paid' ? $invoice->paid_at : $invoice->due_date)->format('d M Y') ?: '—' }}
                </div>
            </td>
            <td>
                <div class="meta-label">Status</div>
                <div class="meta-value"><span class="badge">{{ ucfirst($invoice->status) }}</span></div>
            </td>
        </tr>
    </table>

    {{-- The supplier is the letterhead ($company, filled from the platform's
         billing profile), so only the customer needs a party block here. --}}
    <table class="parties">
        <tr>
            <td>
                @include('documents.partials.party', [
                    'label' => 'Billed To',
                    'name' => $customer['name'] ?: $tenant->company_name,
                    'lines' => [
                        $customer['address'] ?? null,
                        ($customer['gstin'] ?? null) ? 'GSTIN: '.$customer['gstin'] : null,
                        $tenant->email,
                        $tenant->phone ? 'Ph: '.$tenant->phone : null,
                        $stateName ? 'Place of Supply: '.$stateName.' ('.$invoice->place_of_supply.')' : null,
                    ],
                ])
            </td>
            <td>
                @include('documents.partials.party', [
                    'label' => 'Payment Reference',
                    'name' => $invoice->payment_gateway_ref ?: '—',
                    'lines' => [
                        $invoice->payment_method ? 'Method: '.ucwords(str_replace('_', ' ', $invoice->payment_method)) : null,
                        $sub?->razorpay_subscription_id ? 'Subscription: '.$sub->razorpay_subscription_id : null,
                    ],
                ])
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width:28px;">#</th>
                <th>Description</th>
                @if ($invoice->sac_code)
                    <th style="width:70px;">SAC</th>
                @endif
                @if ($invoice->hasTax())
                    <th class="num" style="width:60px;">GST %</th>
                @endif
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>1</td>
                <td>
                    <div class="item-name">
                        @if ($invoice->type === 'pack')
                            {{ $invoice->notes ?: 'Marketplace pack' }}
                        @else
                            {{ $plan?->name ?? 'Subscription' }} plan &middot; {{ ucfirst($plan?->billing_cycle ?? 'monthly') }} subscription
                        @endif
                    </div>
                    @if ($period)
                        <div class="item-sub">Billing period: {{ $period }}</div>
                    @endif
                </td>
                @if ($invoice->sac_code)
                    <td>{{ $invoice->sac_code }}</td>
                @endif
                @if ($invoice->hasTax())
                    <td class="num">{{ rtrim(rtrim(number_format((float) $invoice->tax_rate, 2), '0'), '.') }}%</td>
                @endif
                <td class="num">{{ $money($invoice->subtotal ?: $invoice->amount) }}</td>
            </tr>
        </tbody>
    </table>

    <table class="totals">
        @if ((float) $invoice->discount_amount > 0)
            <tr>
                <td class="label">Subtotal</td>
                <td class="value">{{ $money($invoice->subtotal) }}</td>
            </tr>
            <tr>
                <td class="label">Discount{{ $invoice->coupon_code ? ' ('.$invoice->coupon_code.')' : '' }}</td>
                <td class="value">− {{ $money($invoice->discount_amount) }}</td>
            </tr>
        @endif

        @if ($invoice->hasTax())
            <tr>
                <td class="label">Taxable Value</td>
                <td class="value">{{ $money($taxable) }}</td>
            </tr>
            {{-- Intra-state supplies split the rate in two; inter-state carry IGST. --}}
            @if ((float) $invoice->igst_amount > 0)
                <tr>
                    <td class="label">IGST @ {{ rtrim(rtrim(number_format((float) $invoice->tax_rate, 2), '0'), '.') }}%</td>
                    <td class="value">{{ $money($invoice->igst_amount) }}</td>
                </tr>
            @else
                <tr>
                    <td class="label">CGST @ {{ rtrim(rtrim(number_format((float) $invoice->tax_rate / 2, 2), '0'), '.') }}%</td>
                    <td class="value">{{ $money($invoice->cgst_amount) }}</td>
                </tr>
                <tr>
                    <td class="label">SGST @ {{ rtrim(rtrim(number_format((float) $invoice->tax_rate / 2, 2), '0'), '.') }}%</td>
                    <td class="value">{{ $money($invoice->sgst_amount) }}</td>
                </tr>
            @endif
        @endif

        <tr class="grand">
            <td class="label">Total</td>
            <td class="value">{{ $money($invoice->amount) }}</td>
        </tr>

        @if ((float) $invoice->refund_amount > 0)
            <tr>
                <td class="label">Refunded{{ $invoice->refunded_at ? ' on '.$invoice->refunded_at->format('d M Y') : '' }}</td>
                <td class="value">− {{ $money($invoice->refund_amount) }}</td>
            </tr>
        @endif
    </table>

    @if (! empty($context['terms']))
        <div class="section-title">Terms</div>
        <div class="notes">{{ $context['terms'] }}</div>
    @endif

    @if ($invoice->refund_reason)
        <div class="section-title">Refund Note</div>
        <div class="notes">{{ $invoice->refund_reason }}</div>
    @endif

    @if ($invoice->hasTax() && ($seller['gstin'] ?? null))
        <div class="notes" style="margin-top:10px;">
            This is a computer-generated tax invoice. GST is charged under
            {{ $invoice->is_interstate ? 'IGST (inter-state supply)' : 'CGST + SGST (intra-state supply)' }}.
        </div>
    @endif
@endsection
