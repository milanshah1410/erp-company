@extends('documents.layout', ['docTitle' => 'SUBSCRIPTION INVOICE'])

@section('content')
    @php
        $sub = $invoice->subscription;
        $plan = $sub?->plan;
        $period = $sub && $sub->current_period_start && $sub->current_period_end
            ? $sub->current_period_start->format('d M Y').' – '.$sub->current_period_end->format('d M Y')
            : null;
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
                <div class="meta-label">Paid On</div>
                <div class="meta-value">{{ optional($invoice->paid_at)->format('d M Y') ?: '—' }}</div>
            </td>
            <td>
                <div class="meta-label">Status</div>
                <div class="meta-value"><span class="badge">{{ ucfirst($invoice->status) }}</span></div>
            </td>
        </tr>
    </table>

    <table class="parties">
        <tr>
            <td>
                @include('documents.partials.party', [
                    'label' => 'Billed To',
                    'name' => $tenant->company_name,
                    'lines' => [
                        $tenant->email,
                        $tenant->phone ? 'Ph: '.$tenant->phone : null,
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
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>1</td>
                <td>
                    <div class="item-name">{{ $plan?->name ?? 'Subscription' }} plan &middot; {{ ucfirst($plan?->billing_cycle ?? 'monthly') }} subscription</div>
                    @if ($period)
                        <div class="item-sub">Billing period: {{ $period }}</div>
                    @endif
                </td>
                <td class="num">{{ $currency }} {{ number_format((float) $invoice->amount, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <table class="totals">
        <tr class="grand">
            <td class="label">Total</td>
            <td class="value">{{ $currency }} {{ number_format((float) $invoice->amount, 2) }}</td>
        </tr>
        @if ((float) $invoice->refund_amount > 0)
            <tr>
                <td class="label">Refunded{{ $invoice->refunded_at ? ' on '.$invoice->refunded_at->format('d M Y') : '' }}</td>
                <td class="value">− {{ $currency }} {{ number_format((float) $invoice->refund_amount, 2) }}</td>
            </tr>
        @endif
    </table>

    @if ($invoice->refund_reason)
        <div class="section-title">Refund Note</div>
        <div class="notes">{{ $invoice->refund_reason }}</div>
    @endif
@endsection
