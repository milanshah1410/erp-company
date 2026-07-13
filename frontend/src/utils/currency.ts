import { useAuthStore } from '../stores/auth'

/** Formats an amount using the active company's currency (falls back to INR). */
export function formatCurrency(value: number | string | null | undefined, options: Intl.NumberFormatOptions = {}): string {
  const auth = useAuthStore()
  const n = Number(value ?? 0)
  const code = auth.user?.current_company?.currency_code || 'INR'

  try {
    return n.toLocaleString('en-IN', { style: 'currency', currency: code, ...options })
  } catch {
    const symbol = auth.user?.current_company?.currency_symbol || '₹'

    return `${symbol}${n.toFixed(2)}`
  }
}
