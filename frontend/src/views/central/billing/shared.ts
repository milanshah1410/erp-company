// Shared helpers for the platform Billing & Subscriptions panels.

/** Format an amount in the invoice/plan currency (single-currency SaaS, INR default). */
export function fmtMoney(value: number | string | null | undefined, currency = 'INR'): string {
  const n = Number(value ?? 0)
  try {
    return new Intl.NumberFormat('en-IN', {
      style: 'currency',
      currency: currency || 'INR',
      minimumFractionDigits: 0,
      maximumFractionDigits: 2,
    }).format(n)
  } catch {
    return `${currency} ${n.toFixed(2)}`
  }
}

/** 'YYYY-MM-DD HH:MM:SS' → 'YYYY-MM-DD' (or em-dash when absent). */
export function fmtDate(value: string | null | undefined): string {
  return value ? value.slice(0, 10) : '—'
}

export const subscriptionSeverity: Record<string, string> = {
  trialing: 'info',
  active: 'success',
  past_due: 'warn',
  suspended: 'danger',
  cancelled: 'secondary',
}

export const invoiceSeverity: Record<string, string> = {
  created: 'info',
  paid: 'success',
  failed: 'danger',
  refunded: 'warn',
  void: 'secondary',
}

export const SUBSCRIPTION_STATUSES = ['trialing', 'active', 'past_due', 'suspended', 'cancelled'] as const
export const INVOICE_STATUSES = ['created', 'paid', 'failed', 'refunded', 'void'] as const
export const INVOICE_TYPES = ['subscription', 'pack', 'adjustment'] as const

/** Percentage with trailing zeros trimmed: 18.00 → '18', 2.50 → '2.5'. */
export function fmtPercent(value: number | string | null | undefined): string {
  const n = Number(value ?? 0)
  return `${n.toFixed(2).replace(/\.?0+$/, '')}%`
}

/**
 * One-line summary of a coupon's terms, matching the server's
 * PlatformCoupon::label() so a coupon reads the same wherever it appears.
 */
export function couponSummary(c: {
  discount_type?: string
  discount_value?: number | string
  currency?: string
  duration?: string
  duration_cycles?: number | null
}): string {
  const off =
    c.discount_type === 'percent'
      ? fmtPercent(c.discount_value)
      : fmtMoney(c.discount_value, c.currency)
  const span =
    c.duration === 'repeating'
      ? ` for ${c.duration_cycles ?? 1} cycles`
      : c.duration === 'forever'
        ? ' for the life of the subscription'
        : ''
  return `${off} off${span}`
}
