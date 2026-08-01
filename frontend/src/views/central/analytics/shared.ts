// Shared helpers for the platform Analytics and AI Insights panels.

/**
 * Categorical series colours, assigned in fixed order and never cycled.
 *
 * Validated against the panel surface (#f6f7f9) for the lightness band, chroma
 * floor, adjacent-pair CVD separation and 3:1 contrast — the brand amber
 * (#e8a33d) only reaches 2.1:1 as a data fill, so the chart amber is the darker
 * #b8791a. The platform panel has no dark mode (CentralAdminLayout is a fixed
 * light shell), so there is deliberately no second set of steps here.
 */
export const SERIES = ['#2a78d6', '#b8791a', '#3f9a6a', '#a35bd1'] as const

/** Status colours — reserved for state, never reused as a fifth series. */
export const STATUS = {
  good: '#15803d',
  warning: '#b45309',
  serious: '#c2410c',
  critical: '#b91c1c',
  muted: '#6b7280',
} as const

/** Risk band → PrimeVue Tag severity. */
export const bandSeverity: Record<string, string> = {
  critical: 'danger',
  high: 'warn',
  medium: 'info',
  low: 'success',
}

/** SLA state → PrimeVue Tag severity. */
export const slaSeverity: Record<string, string> = {
  breached: 'danger',
  due_soon: 'warn',
  on_track: 'info',
  met: 'success',
  none: 'secondary',
}

export const slaLabel: Record<string, string> = {
  breached: 'Breached',
  due_soon: 'Due soon',
  on_track: 'On track',
  met: 'Met',
  none: 'No SLA',
}

/** Compact money for axis ticks and tiles (₹1.2L / ₹3.4Cr — Indian grouping). */
export function fmtCompact(value: number | string | null | undefined, currency = 'INR'): string {
  const n = Number(value ?? 0)
  try {
    return new Intl.NumberFormat('en-IN', {
      style: 'currency',
      currency: currency || 'INR',
      notation: 'compact',
      maximumFractionDigits: 1,
    }).format(n)
  } catch {
    return `${currency} ${n.toFixed(0)}`
  }
}

/** Bytes → human size. Null-safe: an unmeasurable disk reads as an em-dash. */
export function fmtBytes(bytes: number | null | undefined): string {
  if (bytes === null || bytes === undefined) return '—'
  if (bytes < 1024) return `${bytes} B`
  const units = ['KB', 'MB', 'GB', 'TB']
  let value = bytes / 1024
  let i = 0
  while (value >= 1024 && i < units.length - 1) {
    value /= 1024
    i++
  }
  return `${value.toFixed(value >= 10 ? 0 : 1)} ${units[i]}`
}

/** Whole numbers with Indian grouping. */
export function fmtNumber(value: number | null | undefined): string {
  return value === null || value === undefined ? '—' : new Intl.NumberFormat('en-IN').format(value)
}

/** A percentage that may legitimately be null ("not enough data to say"). */
export function fmtPct(value: number | null | undefined, digits = 1): string {
  return value === null || value === undefined ? '—' : `${Number(value).toFixed(digits)}%`
}

/**
 * Base chart options: recessive grid and axes, no chart-owned legend (identity
 * is carried by the HTML legend beside each figure so it can be read by a
 * screen reader and is never colour-alone).
 */
export function baseOptions(overrides: Record<string, any> = {}): Record<string, any> {
  return {
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: '#1f2937',
        padding: 10,
        titleFont: { size: 12 },
        bodyFont: { size: 12 },
      },
    },
    scales: {
      y: {
        beginAtZero: true,
        border: { display: false },
        grid: { color: '#eceef1' },
        ticks: { color: '#6b7280', font: { size: 11 } },
      },
      x: {
        border: { display: false },
        grid: { display: false },
        ticks: { color: '#6b7280', font: { size: 11 } },
      },
    },
    ...overrides,
  }
}
