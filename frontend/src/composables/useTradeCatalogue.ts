import { ref } from 'vue'
import http from '../api/http'

// Vocabulary for the Import & Export Logistics screens — document types,
// logistics cost heads, timeline milestones, vendor kinds and the shipment
// status graph — served by GET /import-export/catalogue (App\Support\TradeCatalogue).
//
// Fetched once per session and shared: nine screens need the same option lists,
// and hard-coding them in each would let the UI drift from what the API accepts.

export interface Option { value: string; label: string }
export interface DocumentOption extends Option { legs: string[]; mandatory: boolean }

export interface TradeCatalogue {
  documents: DocumentOption[]
  expense_types: Option[]
  milestones: Option[]
  vendor_types: Option[]
  statuses: string[]
  transitions: Record<string, string[]>
}

const empty: TradeCatalogue = {
  documents: [], expense_types: [], milestones: [], vendor_types: [], statuses: [], transitions: {},
}

const catalogue = ref<TradeCatalogue>(empty)
let inflight: Promise<TradeCatalogue> | null = null

export function useTradeCatalogue() {
  async function load(): Promise<TradeCatalogue> {
    if (catalogue.value.statuses.length) return catalogue.value
    // Concurrent callers (a screen and its dialog) share one request.
    if (!inflight) {
      inflight = http.get('/import-export/catalogue')
        .then(({ data }) => (catalogue.value = { ...empty, ...data }))
        .finally(() => { inflight = null })
    }
    return inflight
  }

  /** Documents valid for a leg — 'import' or 'export'. */
  function documentsFor(leg: string): DocumentOption[] {
    return catalogue.value.documents.filter(d => d.legs.includes(leg))
  }

  /** Statuses a shipment may move to next, from Shipment::TRANSITIONS. */
  function nextStatuses(status: string): string[] {
    return catalogue.value.transitions[status] ?? []
  }

  function labelOf(list: keyof TradeCatalogue, value?: string | null): string {
    if (!value) return '—'
    const options = catalogue.value[list] as Option[]
    return options.find?.(o => o.value === value)?.label ?? value
  }

  return { catalogue, load, documentsFor, nextStatuses, labelOf }
}

/** Shared display helpers for the module's boards. */
export const shipmentStatusSeverity: Record<string, string> = {
  draft: 'secondary',
  booked: 'info',
  in_transit: 'info',
  arrived: 'warn',
  customs: 'warn',
  cleared: 'success',
  delivered: 'success',
  closed: 'secondary',
  cancelled: 'danger',
}

export const customsStatusSeverity: Record<string, string> = {
  pending: 'secondary',
  filed: 'info',
  assessed: 'info',
  duty_paid: 'warn',
  cleared: 'success',
  on_hold: 'danger',
}

export const paymentStatusSeverity: Record<string, string> = {
  unpaid: 'danger',
  partial: 'warn',
  paid: 'success',
}

export const containerStatusSeverity: Record<string, string> = {
  planned: 'secondary',
  stuffed: 'info',
  gated_in: 'info',
  loaded: 'info',
  in_transit: 'info',
  discharged: 'warn',
  gated_out: 'warn',
  delivered: 'success',
  empty_returned: 'success',
}

/** snake_case status → "Snake case", for tags and dropdowns. */
export function humanize(value?: string | null): string {
  if (!value) return '—'
  const s = value.replace(/_/g, ' ')
  return s.charAt(0).toUpperCase() + s.slice(1)
}

/** Options for a PrimeVue Select from a snake_case status list. */
export function statusOptions(values: string[]): Option[] {
  return values.map(value => ({ value, label: humanize(value) }))
}
