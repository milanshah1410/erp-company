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
}

export const SUBSCRIPTION_STATUSES = ['trialing', 'active', 'past_due', 'suspended', 'cancelled'] as const
export const INVOICE_STATUSES = ['created', 'paid', 'failed', 'refunded'] as const
